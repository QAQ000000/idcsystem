<?php

namespace App\Modules\User\Services;

use App\Modules\Finance\Models\Invoice;
use App\Modules\User\Models\Affiliate;
use App\Modules\User\Models\AffiliateCommission;
use App\Modules\User\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AffiliateService
{
    public function getOrCreate(Client $client): Affiliate
    {
        return DB::transaction(function () use ($client) {
            $affiliate = Affiliate::query()
                ->where('client_id', $client->id)
                ->lockForUpdate()
                ->first();

            if ($affiliate) {
                return $affiliate;
            }

            return Affiliate::query()->create([
                'client_id' => $client->id,
                'code' => $this->uniqueCode($client),
                'status' => 'active',
            ]);
        });
    }

    public function recordSignup(Client $referredClient, string $code): void
    {
        $code = trim($code);
        if ($code === '') {
            return;
        }

        DB::transaction(function () use ($referredClient, $code) {
            $affiliate = Affiliate::query()
                ->where('code', $code)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (!$affiliate || (int) $affiliate->client_id === (int) $referredClient->id) {
                return;
            }

            if (AffiliateCommission::query()
                ->where('referred_client_id', $referredClient->id)
                ->where('type', 'signup')
                ->exists()) {
                return;
            }

            AffiliateCommission::query()->create([
                'affiliate_id' => $affiliate->id,
                'referred_client_id' => $referredClient->id,
                'invoice_id' => null,
                'amount' => 0,
                'type' => 'signup',
                'status' => 'approved',
            ]);

            $affiliate->increment('referral_count');
        });
    }

    public function recordPayment(Invoice $invoice): void
    {
        $invoice = $invoice->fresh(['client']);
        if (!$invoice || $invoice->status !== 'Paid' || (float) $invoice->total <= 0) {
            return;
        }

        DB::transaction(function () use ($invoice) {
            $signup = AffiliateCommission::query()
                ->where('referred_client_id', $invoice->client_id)
                ->where('type', 'signup')
                ->whereIn('status', ['approved', 'paid'])
                ->with('affiliate')
                ->lockForUpdate()
                ->first();

            $affiliate = $signup?->affiliate;
            if (!$affiliate || !$affiliate->isActive()) {
                return;
            }

            if (AffiliateCommission::query()
                ->where('affiliate_id', $affiliate->id)
                ->where('invoice_id', $invoice->id)
                ->where('type', 'payment')
                ->exists()) {
                return;
            }

            $amount = $this->calculateCommission((float) $invoice->total);
            if ($amount <= 0) {
                return;
            }

            AffiliateCommission::query()->create([
                'affiliate_id' => $affiliate->id,
                'referred_client_id' => $invoice->client_id,
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'type' => 'payment',
                'status' => 'pending',
            ]);
        });
    }

    public function approve(AffiliateCommission $commission): bool
    {
        return DB::transaction(function () use ($commission) {
            $lockedCommission = AffiliateCommission::query()
                ->whereKey($commission->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedCommission || $lockedCommission->status !== 'pending') {
                return false;
            }

            $affiliate = Affiliate::query()->whereKey($lockedCommission->affiliate_id)->lockForUpdate()->first();
            if (!$affiliate || !$affiliate->isActive()) {
                return false;
            }

            $lockedCommission->update(['status' => 'approved']);
            $affiliate->increment('balance', (float) $lockedCommission->amount);

            return true;
        });
    }

    public function withdraw(Affiliate $affiliate, float $amount): bool
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return false;
        }

        return DB::transaction(function () use ($affiliate, $amount) {
            $lockedAffiliate = Affiliate::query()->whereKey($affiliate->id)->lockForUpdate()->first();
            if (!$lockedAffiliate || (float) $lockedAffiliate->balance < $amount) {
                return false;
            }

            $client = Client::query()->whereKey($lockedAffiliate->client_id)->lockForUpdate()->first();
            if (!$client || !$client->isActive()) {
                return false;
            }

            $paidAmount = 0.0;
            $commissions = AffiliateCommission::query()
                ->where('affiliate_id', $lockedAffiliate->id)
                ->where('status', 'approved')
                ->where('type', 'payment')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($commissions as $commission) {
                if (round($paidAmount + (float) $commission->amount, 2) > $amount) {
                    break;
                }

                $paidAmount = round($paidAmount + (float) $commission->amount, 2);
                $commission->update(['status' => 'paid', 'paid_at' => now()]);
            }

            if ($paidAmount <= 0 || round($paidAmount, 2) !== $amount) {
                return false;
            }

            if (!app(ClientService::class)->addCredit($client, $amount, '分销佣金提现')) {
                return false;
            }

            $lockedAffiliate->decrement('balance', $amount);
            $lockedAffiliate->increment('withdrawn', $amount);

            return true;
        });
    }

    public function calculateCommission(float $invoiceTotal): float
    {
        $rate = max(0, (float) config('billing.affiliate_commission_rate', 10));

        return round($invoiceTotal * ($rate / 100), 2);
    }

    private function uniqueCode(Client $client): string
    {
        do {
            $code = Str::upper(Str::limit(Str::slug($client->username, ''), 12, ''))
                . Str::upper(Str::random(8));
            $code = Str::limit($code, 32, '');
        } while (Affiliate::query()->where('code', $code)->exists());

        return $code;
    }
}
