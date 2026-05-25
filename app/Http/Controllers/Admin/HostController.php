<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Order\Models\Host;
use App\Modules\Order\Services\HostService;
use App\Modules\Product\Models\Product;
use App\Modules\User\Models\Client;
use App\Models\HostActionLog;
use App\Plugins\Contracts\ServerModuleInterface;
use App\Plugins\Facades\Plugin;
use App\Services\AdminAuditService;
use App\Services\HostMonitoringService;
use Illuminate\Http\Request;
use Throwable;

class HostController extends Controller
{
    public function index(Request $request)
    {
        $filters = [
            'client_id' => $this->queryInteger($request, 'client_id'),
            'product_id' => $this->queryInteger($request, 'product_id'),
            'status' => $this->queryString($request, 'status'),
        ];

        $hosts = Host::query()
            ->with([
                'client',
                'product',
                'actionLogs' => fn ($query) => $query->where('action', 'like', '%\_failed')->latest(),
            ])
            ->when($filters['client_id'], fn ($query, int $clientId) => $query->where('client_id', $clientId))
            ->when($filters['product_id'], fn ($query, int $productId) => $query->where('product_id', $productId))
            ->when($filters['status'], fn ($query, string $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.hosts.index', [
            'hosts' => $hosts,
            'clients' => Client::withTrashed()->orderBy('username')->get(['id', 'username', 'email', 'deleted_at']),
            'products' => Product::query()->orderBy('name')->get(['id', 'name']),
            'statuses' => ['Pending', 'Active', 'Suspended', 'Terminated', 'Cancelled'],
            'filters' => $filters,
        ]);
    }

    public function show(Host $host, HostMonitoringService $monitoring)
    {
        $host->load([
            'client',
            'product',
            'order.invoice.items',
            'actionLogs' => fn ($query) => $query->latest(),
            'usageSnapshots' => fn ($query) => $query->latest('collected_at')->limit(5),
            'upgrades',
        ]);

        $serverPlugin = $this->serverPlugin($host);
        $usageStats = [];
        $usageError = null;

        if ($serverPlugin) {
            try {
                $usageStats = $monitoring->normalizeUsageStats(
                    $serverPlugin->getUsageStats($this->usageStatsParams($host))
                );
            } catch (Throwable $exception) {
                $usageError = $exception->getMessage();
                $this->logUsageQueryFailure($host, $usageError);
            }
        } elseif ($host->product?->server_type) {
            $usageError = '服务器模块不可用：' . $host->product->server_type;
        }

        $failureLog = $host->status === 'Pending'
            ? $host->actionLogs->first(fn ($log) => $log->action === 'provision_failed')
            : null;
        $provisionPayable = $this->isProvisionPayable($host);

        return view('admin.hosts.show', compact('host', 'usageStats', 'usageError', 'failureLog', 'provisionPayable'));
    }

    private function logUsageQueryFailure(Host $host, string $message): void
    {
        $recentlyLogged = HostActionLog::query()
            ->where('host_id', $host->id)
            ->where('action', 'usage_query_failed')
            ->where('message', $message)
            ->where('created_at', '>=', now()->subMinutes(15))
            ->exists();

        if ($recentlyLogged) {
            return;
        }

        HostActionLog::query()->create([
            'host_id' => $host->id,
            'action' => 'usage_query_failed',
            'message' => $message,
            'meta' => ['source' => 'admin.hosts.show'],
        ]);
    }

    public function action(Request $request, Host $host, HostService $hosts, AdminAuditService $audit)
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'in:provision,suspend,unsuspend,terminate,reset_password'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $success = match ($data['action']) {
            'provision' => $hosts->provision($host),
            'suspend' => $hosts->suspend($host, ($data['reason'] ?? null) ?: '后台手动暂停'),
            'unsuspend' => $hosts->unsuspend($host),
            'terminate' => $hosts->terminate($host),
            'reset_password' => $hosts->resetPassword($host),
        };
        $audit->record($request, 'host.' . $data['action'], $host, $success ? 'success' : 'failed', [
            'reason' => $data['reason'] ?? null,
            'host_status' => $host->fresh()->status,
        ], $success ? null : '服务操作失败');

        return redirect()
            ->route('admin.hosts.show', $host)
            ->with($success ? 'status' : 'error', $success ? '服务操作已执行' : '服务操作失败，请查看操作日志');
    }

    private function serverPlugin(Host $host): ?ServerModuleInterface
    {
        $serverType = $host->product?->server_type;
        if (!$serverType) {
            return null;
        }

        $plugin = Plugin::get($serverType);

        return $plugin instanceof ServerModuleInterface ? $plugin : null;
    }

    private function usageStatsParams(Host $host): array
    {
        return [
            'host_id' => $host->id,
            'client_id' => $host->client_id,
            'product_id' => $host->product_id,
            'domain' => $host->domain,
            'username' => $host->username,
            'billing_cycle' => $host->billing_cycle,
            'server_id' => $host->server_id,
            'config' => is_array($host->notes) ? $host->notes : [],
        ];
    }

    private function isProvisionPayable(Host $host): bool
    {
        if (!$host->order_id) {
            return true;
        }

        return $host->order?->status === 'Paid'
            && (!$host->order?->invoice || $host->order->invoice->status === 'Paid');
    }

    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function queryInteger(Request $request, string $key): ?int
    {
        $value = $this->queryString($request, $key);

        if ($value === null || !ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }
}
