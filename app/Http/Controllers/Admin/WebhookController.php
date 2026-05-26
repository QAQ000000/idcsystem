<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Webhook;
use App\Services\AdminAuditService;
use App\Services\WebhookService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WebhookController extends Controller
{
    public const EVENTS = [
        'order.created' => '订单创建',
        'invoice.paid' => '账单支付',
        'host.suspended' => '服务暂停',
        'host.unsuspended' => '服务解除暂停',
        'host.terminated' => '服务终止',
        'webhook.test' => '测试事件',
    ];

    public function index(): View
    {
        return view('admin.webhooks.index', [
            'webhooks' => Webhook::query()->withCount('deliveries')->latest()->paginate(20),
            'events' => self::EVENTS,
        ]);
    }

    public function create(): View
    {
        return view('admin.webhooks.create', [
            'webhook' => new Webhook(['active' => true, 'events' => []]),
            'events' => self::EVENTS,
        ]);
    }

    public function store(Request $request, AdminAuditService $audit): RedirectResponse
    {
        $data = $this->validated($request);
        $webhook = Webhook::query()->create($data);
        $audit->record($request, 'webhook.create', $webhook, 'success', $data);

        return redirect()->route('admin.webhooks.index')->with('status', 'Webhook 已创建');
    }

    public function edit(Webhook $webhook): View
    {
        return view('admin.webhooks.edit', [
            'webhook' => $webhook,
            'events' => self::EVENTS,
        ]);
    }

    public function update(Request $request, Webhook $webhook, AdminAuditService $audit): RedirectResponse
    {
        $data = $this->validated($request, $webhook);
        $webhook->update($data);
        $audit->record($request, 'webhook.update', $webhook, 'success', $data);

        return redirect()->route('admin.webhooks.index')->with('status', 'Webhook 已保存');
    }

    public function destroy(Request $request, Webhook $webhook, AdminAuditService $audit): RedirectResponse
    {
        $webhookId = $webhook->id;
        $webhook->delete();
        $audit->record($request, 'webhook.delete', null, 'success', ['webhook_id' => $webhookId]);

        return redirect()->route('admin.webhooks.index')->with('status', 'Webhook 已删除');
    }

    public function deliveries(Webhook $webhook): View
    {
        return view('admin.webhooks.deliveries', [
            'webhook' => $webhook,
            'deliveries' => $webhook->deliveries()->latest()->paginate(20),
        ]);
    }

    public function test(Request $request, Webhook $webhook, WebhookService $webhooks, AdminAuditService $audit): RedirectResponse
    {
        $delivery = $webhooks->test($webhook);
        $webhooks->deliver($delivery);
        $audit->record($request, 'webhook.test', $webhook, $delivery->fresh()->status, [
            'delivery_id' => $delivery->id,
        ]);

        return redirect()->route('admin.webhooks.deliveries', $webhook)->with('status', '测试 Webhook 已发送');
    }

    private function validated(Request $request, ?Webhook $webhook = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'url' => ['required', 'url', 'max:500'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(array_keys(self::EVENTS))],
            'secret' => ['nullable', 'string', 'max:64'],
            'active' => ['nullable', 'boolean'],
        ]);

        $data['events'] = array_values(array_unique($data['events']));
        $data['secret'] = $data['secret'] ?: ($webhook?->secret ?: Str::random(64));
        $data['active'] = $request->boolean('active');

        return $data;
    }
}
