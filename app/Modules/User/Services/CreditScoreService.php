<?php

namespace App\Modules\User\Services;

use App\Models\HostActionLog;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Order\Models\Host;
use App\Modules\User\Models\Client;
use App\Modules\User\Models\CreditScoreLog;
use Illuminate\Support\Facades\DB;

class CreditScoreService
{
    public const BASE_SCORE = 100;
    public const MIN_SCORE = 0;
    public const MAX_SCORE = 150;

    public function calculate(Client $client): int
    {
        $paymentCount = Account::query()
            ->where('client_id', $client->id)
            ->where('type', 'credit')
            ->whereNotNull('invoice_id')
            ->count();

        $refundCount = Account::query()
            ->where('client_id', $client->id)
            ->where('type', 'debit')
            ->whereNotNull('invoice_id')
            ->count();

        $overdueCount = HostActionLog::query()
            ->where('action', 'suspend')
            ->where('message', 'Overdue payment')
            ->whereHas('host', fn ($query) => $query->where('client_id', $client->id))
            ->distinct('host_id')
            ->count('host_id');

        return $this->clamp(self::BASE_SCORE + ($paymentCount * 2) - ($overdueCount * 5) - ($refundCount * 3));
    }

    public function updateScore(Client $client, string $reason, array $details = [], ?string $eventKey = null): void
    {
        DB::transaction(function () use ($client, $reason, $details, $eventKey): void {
            if ($eventKey && CreditScoreLog::query()->where('event_key', $eventKey)->exists()) {
                return;
            }

            $lockedClient = Client::query()->whereKey($client->id)->lockForUpdate()->first();
            if (!$lockedClient) {
                return;
            }

            $oldScore = (int) ($lockedClient->credit_score ?? self::BASE_SCORE);
            $newScore = $this->clamp((int) ($details['score'] ?? $oldScore + (int) ($details['delta'] ?? 0)));

            $lockedClient->forceFill([
                'credit_score' => $newScore,
                'credit_level' => $this->getLevel($newScore),
                'credit_score_updated_at' => now(),
            ])->save();

            CreditScoreLog::query()->create([
                'client_id' => $lockedClient->id,
                'old_score' => $oldScore,
                'new_score' => $newScore,
                'reason' => $reason,
                'event_key' => $eventKey,
                'details' => $details,
                'created_at' => now(),
            ]);
        });
    }

    public function getLevel(int $score): string
    {
        return match (true) {
            $score >= 90 => 'Excellent',
            $score >= 70 => 'Good',
            $score >= 50 => 'Fair',
            default => 'Poor',
        };
    }

    public function adjustForPayment(Client $client, Invoice $invoice): void
    {
        $this->updateScore($client, 'payment_success', [
            'delta' => 2,
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'amount' => (float) $invoice->total,
        ], 'payment:invoice:' . $invoice->id);
    }

    public function adjustForOverdue(Client $client, Host $host): void
    {
        $this->updateScore($client, 'overdue_suspend', [
            'delta' => -5,
            'host_id' => $host->id,
            'product_id' => $host->product_id,
        ], 'overdue:host:' . $host->id);
    }

    public function adjustForRefund(Client $client, Invoice $invoice, ?float $amount = null, ?float $refundedTotal = null): void
    {
        $suffix = $refundedTotal === null ? (string) now()->getTimestampMs() : number_format($refundedTotal, 2, '.', '');
        $this->updateScore($client, 'invoice_refund', [
            'delta' => -3,
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'amount' => $amount,
            'refunded_total' => $refundedTotal,
        ], 'refund:invoice:' . $invoice->id . ':' . $suffix);
    }

    public function recalculateAll(): int
    {
        $count = 0;

        Client::query()->chunkById(100, function ($clients) use (&$count): void {
            foreach ($clients as $client) {
                $score = $this->calculate($client);
                $this->updateScore($client, 'recalculate', ['score' => $score]);
                $count++;
            }
        });

        return $count;
    }

    private function clamp(int $score): int
    {
        return max(self::MIN_SCORE, min(self::MAX_SCORE, $score));
    }
}
