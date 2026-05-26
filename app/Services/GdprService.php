<?php

namespace App\Services;

use App\Jobs\ExportClientDataJob;
use App\Models\ClientActivityLog;
use App\Models\ClientLoginLog;
use App\Models\DataDeletionRequest;
use App\Models\DataExportRequest;
use App\Models\PrivacyPolicyConsent;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Order\Models\Host;
use App\Modules\Order\Models\Order;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\User\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GdprService
{
    public function requestDataExport(Client $client): DataExportRequest
    {
        $request = DataExportRequest::query()->create([
            'client_id' => $client->id,
            'status' => 'pending',
        ]);

        ExportClientDataJob::dispatch($request->id);

        return $request;
    }

    public function exportData(DataExportRequest $request): string
    {
        $request->loadMissing('client');
        if (!$request->client) {
            throw new \RuntimeException('客户不存在。');
        }

        $request->update(['status' => 'processing', 'error_message' => null]);
        $payload = $this->clientPayload($request->client);
        $path = 'gdpr/exports/client-' . $request->client_id . '-' . now()->format('YmdHis') . '.json';
        Storage::disk('local')->put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $request->update([
            'status' => 'completed',
            'file_path' => $path,
            'completed_at' => now(),
        ]);

        return $path;
    }

    public function requestDataDeletion(Client $client, ?string $reason): DataDeletionRequest
    {
        return DataDeletionRequest::query()->create([
            'client_id' => $client->id,
            'reason' => $reason,
            'status' => 'pending',
        ]);
    }

    public function approveDataDeletion(DataDeletionRequest $request, ?string $adminNotes = null): bool
    {
        if ($request->status !== 'pending') {
            return false;
        }

        $request->update([
            'status' => 'approved',
            'admin_notes' => $adminNotes,
            'approved_at' => now(),
        ]);

        return $this->deleteData($request->fresh(['client']));
    }

    public function rejectDataDeletion(DataDeletionRequest $request, ?string $adminNotes = null): bool
    {
        if ($request->status !== 'pending') {
            return false;
        }

        return $request->update([
            'status' => 'rejected',
            'admin_notes' => $adminNotes,
        ]);
    }

    public function deleteData(DataDeletionRequest $request): bool
    {
        $request->loadMissing('client');
        if (!$request->client) {
            return false;
        }

        return DB::transaction(function () use ($request): bool {
            $client = Client::withTrashed()->whereKey($request->client_id)->lockForUpdate()->first();
            if (!$client) {
                return false;
            }

            // 财务和服务历史需要保留审计链路；这里执行可逆风险更低的匿名化和账户关闭。
            $client->update([
                'username' => 'deleted-client-' . $client->id,
                'email' => 'deleted-client-' . $client->id . '@privacy.local',
                'password' => Hash::make(Str::random(40)),
                'status' => 2,
                'company_name' => null,
                'phone_code' => '',
                'phone' => null,
                'country' => null,
                'province' => null,
                'city' => null,
                'address' => null,
                'country_code' => null,
                'state_code' => null,
                'two_factor_enabled' => false,
                'two_factor_secret' => null,
                'notification_preferences' => [],
                'last_login_ip' => null,
                'remember_token' => null,
            ]);

            ClientLoginLog::query()->where('client_id', $client->id)->update([
                'ip' => '0.0.0.0',
                'user_agent' => null,
            ]);

            ClientActivityLog::query()->create([
                'client_id' => $client->id,
                'action' => 'gdpr.data_deleted',
                'description' => '客户数据删除请求已完成，账户已匿名化并关闭',
                'meta' => ['request_id' => $request->id],
                'ip' => null,
                'created_at' => now(),
            ]);

            $request->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            return true;
        });
    }

    public function recordConsent(Client $client, string $policyVersion, ?string $ip = null): void
    {
        PrivacyPolicyConsent::query()->create([
            'client_id' => $client->id,
            'policy_version' => $policyVersion,
            'ip' => $ip ?: '0.0.0.0',
            'consented_at' => now(),
        ]);
    }

    private function clientPayload(Client $client): array
    {
        return [
            'profile' => $client->only([
                'id', 'username', 'email', 'status', 'company_name', 'phone_code', 'phone',
                'country', 'province', 'city', 'address', 'country_code', 'state_code',
                'currency_id', 'locale', 'created_at', 'updated_at',
            ]),
            'orders' => Order::query()->where('client_id', $client->id)->with('hosts')->get()->toArray(),
            'invoices' => Invoice::query()->where('client_id', $client->id)->with(['items', 'accounts', 'receipts'])->get()->toArray(),
            'hosts' => Host::query()->where('client_id', $client->id)->with(['product', 'addons', 'usageAlerts'])->get()->toArray(),
            'domains' => $client->domains()->get()->toArray(),
            'ssl_certificates' => $client->sslCertificates()->get()->makeHidden(['private_key'])->toArray(),
            'tickets' => Ticket::query()->where('client_id', $client->id)->with(['replies', 'status', 'department'])->get()->toArray(),
            'activity_logs' => ClientActivityLog::query()->where('client_id', $client->id)->latest('created_at')->get()->toArray(),
            'login_logs' => ClientLoginLog::query()->where('client_id', $client->id)->latest('logged_in_at')->get()->toArray(),
            'privacy_policy_consents' => $client->privacyPolicyConsents()->get()->toArray(),
        ];
    }
}
