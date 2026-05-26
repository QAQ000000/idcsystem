<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use App\Services\AdminAuditService;
use App\Services\MailService;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmailTemplateController extends Controller
{
    public function index(): View
    {
        return view('admin.email-templates.index', [
            'templates' => EmailTemplate::query()->orderBy('name')->paginate(20),
        ]);
    }

    public function edit(EmailTemplate $emailTemplate): View
    {
        return view('admin.email-templates.edit', compact('emailTemplate'));
    }

    public function preview(EmailTemplate $emailTemplate, MailService $mail): View
    {
        $variables = $this->sampleVariables($emailTemplate->name);

        return view('admin.email-templates.preview', [
            'emailTemplate' => $emailTemplate,
            'variables' => $variables,
            'renderedSubject' => $mail->render($emailTemplate->subject, $variables),
            'renderedBody' => $mail->render($emailTemplate->body, $variables),
        ]);
    }

    public function test(
        Request $request,
        EmailTemplate $emailTemplate,
        MailService $mail,
        AdminAuditService $audit
    ): RedirectResponse {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);
        $variables = $this->sampleVariables($emailTemplate->name);
        $sent = $mail->send(
            $data['email'],
            '[测试] ' . $mail->render($emailTemplate->subject, $variables),
            $mail->render($emailTemplate->body, $variables),
            [
                'template' => $emailTemplate->name,
                'payload' => $variables + ['test_send' => true],
            ]
        );

        $audit->record($request, 'email_template.test', $emailTemplate, $sent ? 'success' : 'failed', [
            'email' => $data['email'],
            'template' => $emailTemplate->name,
        ], $sent ? null : '测试邮件发送失败');

        return redirect()->route('admin.email-templates.preview', $emailTemplate)
            ->with($sent ? 'status' : 'error', $sent ? '测试邮件已发送' : '测试邮件发送失败');
    }

    public function update(Request $request, EmailTemplate $emailTemplate, AdminAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $emailTemplate->update([
            'subject' => $data['subject'],
            'body' => $data['body'],
            'enabled' => $request->boolean('enabled'),
        ]);
        $audit->record($request, 'email_template.update', $emailTemplate, 'success', [
            'subject' => $data['subject'],
            'enabled' => $request->boolean('enabled'),
        ]);

        return redirect()->route('admin.email-templates.index')->with('status', '邮件模板已保存');
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
            'body' => '这是一封测试邮件正文',
            'host_id' => '10001',
            'cancel_type' => 'end_of_billing_period',
            'cancel_reason' => '测试取消原因',
            'admin_notes' => '测试管理员备注',
        ]);
    }
}
