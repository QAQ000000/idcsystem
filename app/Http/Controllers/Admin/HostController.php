<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Order\Models\Host;
use App\Modules\Order\Services\HostService;
use App\Modules\Product\Models\Product;
use App\Modules\User\Models\Client;
use App\Plugins\Contracts\ServerModuleInterface;
use App\Plugins\Facades\Plugin;
use App\Services\AdminAuditService;
use Illuminate\Http\Request;

class HostController extends Controller
{
    public function index(Request $request)
    {
        $hosts = Host::query()
            ->with([
                'client',
                'product',
                'actionLogs' => fn ($query) => $query->where('action', 'like', '%\_failed')->latest(),
            ])
            ->when($request->filled('client_id'), fn ($query) => $query->where('client_id', $request->integer('client_id')))
            ->when($request->filled('product_id'), fn ($query) => $query->where('product_id', $request->integer('product_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.hosts.index', [
            'hosts' => $hosts,
            'clients' => Client::query()->orderBy('username')->get(['id', 'username', 'email']),
            'products' => Product::query()->orderBy('name')->get(['id', 'name']),
            'statuses' => ['Pending', 'Active', 'Suspended', 'Terminated', 'Cancelled'],
            'filters' => $request->only(['client_id', 'product_id', 'status']),
        ]);
    }

    public function show(Host $host)
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
        $usageStats = $serverPlugin ? $serverPlugin->getUsageStats($this->serverParams($host)) : [];
        $failureLog = $host->actionLogs->first(fn ($log) => str_ends_with((string) $log->action, '_failed'));

        return view('admin.hosts.show', compact('host', 'usageStats', 'failureLog'));
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

    private function serverParams(Host $host): array
    {
        return [
            'host_id' => $host->id,
            'client_id' => $host->client_id,
            'product_id' => $host->product_id,
            'domain' => $host->domain,
            'username' => $host->username,
            'password' => $host->password,
            'billing_cycle' => $host->billing_cycle,
            'server_id' => $host->server_id,
            'config' => is_array($host->notes) ? $host->notes : [],
        ];
    }
}
