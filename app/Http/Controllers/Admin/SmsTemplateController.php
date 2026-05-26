<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SmsTemplate;
use App\Services\AdminAuditService;
use App\Services\NotificationService;
use App\Services\SmsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SmsTemplateController extends Controller
{
    public function index(): View
    {
        return view('admin.sms-templates.index', [
            'templates' => SmsTemplate::query()->orderBy('name')->paginate(20),
        ]);
    }

    public function edit(SmsTemplate $smsTemplate): View
    {
        return view('admin.sms-templates.edit', compact('smsTemplate'));
    }

    public function preview(SmsTemplate $smsTemplate, SmsService $sms): View
    {
        $variables = $this->sampleVariables($smsTemplate->name);

        return view('admin.sms-templates.preview', [
            'smsTemplate' => $smsTemplate,
            'variables' => $variables,
            'renderedContent' => $sms->render($smsTemplate->content, $variables),
        ]);
    }

    public function test(
        Request $request,
        SmsTemplate $smsTemplate,
        SmsService $sms,
        AdminAuditService $audit
    ): RedirectResponse {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:50'],
        ]);
        $variables = $this->sampleVariables($smsTemplate->name);
        $sent = $sms->send($data['phone'], $smsTemplate->name, $variables + ['test_send' => true]);

        $audit->record($request, 'sms_template.test', $smsTemplate, $sent ? 'success' : 'failed', [
            'phone' => $data['phone'],
            'template' => $smsTemplate->name,
        ], $sent ? null : '测试短信发送失败');

        return redirect()->route('admin.sms-templates.preview', $smsTemplate)
            ->with($sent ? 'status' : 'error', $sent ? '测试短信已发送' : '测试短信发送失败');
    }

    public function update(Request $request, SmsTemplate $smsTemplate, AdminAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'content' => ['required', 'string'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $smsTemplate->update([
            'content' => $data['content'],
            'enabled' => $request->boolean('enabled'),
        ]);
        $audit->record($request, 'sms_template.update', $smsTemplate, 'success', [
            'enabled' => $request->boolean('enabled'),
        ]);

        return redirect()->route('admin.sms-templates.index')->with('status', '短信模板已保存');
    }

    private function sampleVariables(string $event): array
    {
        return array_merge(array_fill_keys(NotificationService::events()[$event]['variables'] ?? [], '测试值'), [
            'client_name' => '测试客户',
            'invoice_number' => 'INV-TEST-001',
            'amount' => '99.00',
            'product_name' => '测试产品',
            'domain' => 'test.example.com',
            'ticket_number' => 'TICK-TEST-001',
            'reply_message' => '这是一条测试回复',
            'verify_url' => url('/email/verify/test'),
            'receipt_title' => '测试发票抬头',
            'subject' => '测试主题',
            'body' => '这是一条测试短信正文',
            'host_id' => '10001',
            'cancel_type' => 'end_of_billing_period',
            'cancel_reason' => '测试取消原因',
            'admin_notes' => '测试管理员备注',
        ]);
    }
}
