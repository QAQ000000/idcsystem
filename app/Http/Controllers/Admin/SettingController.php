<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Currency;
use App\Services\SettingsService;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index(SettingsService $settings)
    {
        return view('admin.settings.index', [
            'settings' => $settings->all(),
            'currencies' => Currency::query()->orderByDesc('is_default')->orderBy('code')->get(),
        ]);
    }

    public function update(Request $request, SettingsService $settings)
    {
        $data = $request->validate([
            'site_name' => ['required', 'string', 'max:100'],
            'site_url' => ['required', 'url', 'max:255'],
            'default_currency' => ['required', 'string', 'max:10'],
            'maintenance_mode' => ['nullable', 'boolean'],
            'auto_setup_policy' => ['required', 'string', 'max:50'],
            'invoice_due_days' => ['required', 'integer', 'min:0', 'max:365'],
            'renewal_reminder_days' => ['required', 'integer', 'min:0', 'max:365'],
            'mail_from_name' => ['nullable', 'string', 'max:100'],
            'mail_from_address' => ['nullable', 'email', 'max:255'],
            'smtp_host' => ['nullable', 'string', 'max:255'],
            'smtp_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:255'],
            'smtp_encryption' => ['nullable', 'string', 'max:20'],
            'default_email_provider' => ['nullable', 'string', 'max:100'],
            'mail_queue_enabled' => ['nullable', 'boolean'],
            'notify_invoice_created_mail' => ['nullable', 'boolean'],
            'notify_invoice_created_sms' => ['nullable', 'boolean'],
            'notify_invoice_paid_mail' => ['nullable', 'boolean'],
            'notify_invoice_paid_sms' => ['nullable', 'boolean'],
            'notify_ticket_replied_mail' => ['nullable', 'boolean'],
            'notify_ticket_replied_sms' => ['nullable', 'boolean'],
            'default_sms_provider' => ['nullable', 'string', 'max:100'],
            'sms_signature' => ['nullable', 'string', 'max:100'],
            'sms_queue_enabled' => ['nullable', 'boolean'],
        ]);

        $settings->setMany([
            'site_name' => $data['site_name'],
            'site_url' => $data['site_url'],
            'default_currency' => $data['default_currency'],
            'maintenance_mode' => $request->boolean('maintenance_mode'),
        ], 'general');

        $settings->setMany([
            'auto_setup_policy' => $data['auto_setup_policy'],
            'invoice_due_days' => $data['invoice_due_days'],
            'renewal_reminder_days' => $data['renewal_reminder_days'],
        ], 'order');

        $settings->setMany([
            'mail_from_name' => $data['mail_from_name'] ?? '',
            'mail_from_address' => $data['mail_from_address'] ?? '',
            'smtp_host' => $data['smtp_host'] ?? '',
            'smtp_port' => $data['smtp_port'] ?? '',
            'smtp_username' => $data['smtp_username'] ?? '',
            'smtp_password' => $data['smtp_password'] ?? '',
            'smtp_encryption' => $data['smtp_encryption'] ?? '',
            'default_email_provider' => $data['default_email_provider'] ?? 'smtp',
            'mail_queue_enabled' => $request->boolean('mail_queue_enabled'),
        ], 'mail');

        $settings->setMany([
            'default_sms_provider' => $data['default_sms_provider'] ?? 'aliyun',
            'sms_signature' => $data['sms_signature'] ?? '',
            'sms_queue_enabled' => $request->boolean('sms_queue_enabled'),
        ], 'sms');

        $settings->setMany([
            'notify_invoice_created_mail' => $request->boolean('notify_invoice_created_mail', true),
            'notify_invoice_created_sms' => $request->boolean('notify_invoice_created_sms', true),
            'notify_invoice_paid_mail' => $request->boolean('notify_invoice_paid_mail', true),
            'notify_invoice_paid_sms' => $request->boolean('notify_invoice_paid_sms', true),
            'notify_ticket_replied_mail' => $request->boolean('notify_ticket_replied_mail', true),
            'notify_ticket_replied_sms' => $request->boolean('notify_ticket_replied_sms', true),
        ], 'notification');

        return redirect()->route('admin.settings.index')->with('status', '系统设置已保存');
    }
}
