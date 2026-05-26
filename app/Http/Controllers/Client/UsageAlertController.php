<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\UsageAlert;
use App\Modules\Order\Models\Host;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UsageAlertController extends Controller
{
    public function index(Host $host)
    {
        $this->authorizeHost($host);
        $host->load([
            'product',
            'usageAlerts' => fn ($query) => $query->orderBy('metric'),
            'usageAlertLogs' => fn ($query) => $query->latest('triggered_at')->limit(20),
            'usageSnapshots' => fn ($query) => $query->latest('collected_at')->limit(1),
        ]);

        return view('theme::hosts.alerts', [
            'host' => $host,
            'metrics' => $this->metrics(),
            'latestSnapshot' => $host->usageSnapshots->first(),
        ]);
    }

    public function store(Request $request, Host $host)
    {
        $this->authorizeHost($host);
        abort_unless(in_array($host->status, ['Active', 'Suspended'], true), 403);

        $data = $request->validate([
            'metric' => ['required', Rule::in(array_keys($this->metrics()))],
            'threshold' => ['required', 'integer', 'min:1', 'max:100'],
            'active' => ['nullable', 'boolean'],
        ]);

        UsageAlert::query()->updateOrCreate(
            ['host_id' => $host->id, 'metric' => $data['metric']],
            [
                'threshold' => (int) $data['threshold'],
                'active' => $request->boolean('active', true),
            ]
        );

        return redirect()->route('client.hosts.alerts.index', $host)->with('status', '用量告警已保存');
    }

    public function destroy(Host $host, UsageAlert $alert)
    {
        $this->authorizeHost($host);
        abort_unless((int) $alert->host_id === (int) $host->id, 404);
        $alert->delete();

        return redirect()->route('client.hosts.alerts.index', $host)->with('status', '用量告警已删除');
    }

    private function authorizeHost(Host $host): void
    {
        $client = Auth::guard('client')->user();

        abort_unless($client && (int) $host->client_id === (int) $client->id, 403);
    }

    private function metrics(): array
    {
        return [
            'cpu' => 'CPU',
            'memory' => '内存',
            'disk' => '磁盘',
            'bandwidth' => '带宽',
        ];
    }
}
