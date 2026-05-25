<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Currency;
use App\Services\AdminAuditService;
use App\Services\NotificationService;
use App\Services\SettingsService;
use App\Services\ThemeService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingController extends Controller
{
    public function index(SettingsService $settings, ThemeService $themes)
    {
        return view('admin.settings.index', [
            'settings' => $settings,
            'currencies' => Currency::query()->orderByDesc('is_default')->orderBy('code')->get(),
            'notificationEvents' => NotificationService::events(),
            'themes' => $themes->available(),
        ]);
    }

    public function update(Request $request, SettingsService $settings, ThemeService $themes, AdminAuditService $audit)
    {
        $data = $request->validate([
            'site_name' => ['required', 'string', 'max:100'],
            'site_url' => ['required', 'url', 'max:255'],
            'default_currency' => ['required', 'string', 'max:10', Rule::exists('currencies', 'code')],
            'theme' => ['nullable', 'string', Rule::in($themes->available())],
            'maintenance_mode' => ['nullable', 'boolean'],
            'captcha_enabled' => ['nullable', 'boolean'],
            'auto_setup_policy' => ['required', 'string', Rule::in(['manual', 'paid', 'instant'])],
            'invoice_due_days' => ['required', 'integer', 'min:0', 'max:365'],
            'renewal_reminder_days' => ['required', 'integer', 'min:0', 'max:365'],
            'billing_tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'billing_grace_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'billing_invoice_days_before_due' => ['nullable', 'integer', 'min:0', 'max:365'],
            'mail_from_name' => ['nullable', 'string', 'max:100'],
            'mail_from_address' => ['nullable', 'email', 'max:255'],
            'smtp_host' => ['nullable', 'string', 'max:255'],
            'smtp_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:255'],
            'smtp_encryption' => ['nullable', 'string', Rule::in(['', 'tls', 'ssl'])],
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
            'theme' => $data['theme'] ?? $settings->get('theme', 'default'),
            'maintenance_mode' => $request->boolean('maintenance_mode'),
            'captcha_enabled' => $request->boolean('captcha_enabled'),
        ], 'general');
        $this->syncDefaultCurrency($data['default_currency']);

        $settings->setMany([
            'auto_setup_policy' => $data['auto_setup_policy'],
            'invoice_due_days' => $data['invoice_due_days'],
            'renewal_reminder_days' => $data['renewal_reminder_days'],
        ], 'order');

        $settings->setMany([
            'billing_tax_rate' => $data['billing_tax_rate'] ?? config('billing.tax_rate', 0),
            'billing_due_days' => $data['invoice_due_days'],
            'billing_reminder_days' => $data['renewal_reminder_days'],
            'billing_grace_days' => $data['billing_grace_days'] ?? config('billing.grace_days', 0),
            'billing_invoice_days_before_due' => $data['billing_invoice_days_before_due'] ?? config('billing.invoice_days_before_due', 7),
        ], 'billing');

        $settings->setMany([
            'mail_from_name' => $data['mail_from_name'] ?? '',
            'mail_from_address' => $data['mail_from_address'] ?? '',
            'smtp_host' => $data['smtp_host'] ?? '',
            'smtp_port' => $data['smtp_port'] ?? '',
            'smtp_username' => $data['smtp_username'] ?? '',
            'smtp_encryption' => $data['smtp_encryption'] ?? '',
            'default_email_provider' => $data['default_email_provider'] ?? 'smtp',
            'mail_queue_enabled' => $request->boolean('mail_queue_enabled'),
        ], 'mail');

        if (($data['smtp_password'] ?? '') !== '') {
            $settings->set('smtp_password', $data['smtp_password'], 'mail');
        }

        $settings->setMany([
            'default_sms_provider' => $data['default_sms_provider'] ?? 'aliyun',
            'sms_signature' => $data['sms_signature'] ?? '',
            'sms_queue_enabled' => $request->boolean('sms_queue_enabled'),
        ], 'sms');

        $settings->setMany($this->notificationSettings($request), 'notification');
        $audit->record($request, 'settings.update', null, 'success', $data + [
            'theme' => $data['theme'] ?? $settings->get('theme', 'default'),
            'maintenance_mode' => $request->boolean('maintenance_mode'),
            'captcha_enabled' => $request->boolean('captcha_enabled'),
            'mail_queue_enabled' => $request->boolean('mail_queue_enabled'),
            'sms_queue_enabled' => $request->boolean('sms_queue_enabled'),
        ]);

        return redirect()->route('admin.settings.index')->with('status', '系统设置已保存');
    }

    private function notificationSettings(Request $request): array
    {
        $settings = [];

        foreach (array_keys(NotificationService::events()) as $event) {
            foreach (['mail', 'sms'] as $channel) {
                $key = "notify_{$event}_{$channel}";
                $settings[$key] = $request->boolean($key);
            }
        }

        return $settings;
    }

    private function syncDefaultCurrency(string $code): void
    {
        Currency::query()->where('code', '!=', $code)->update(['is_default' => false]);
        Currency::query()->where('code', $code)->update(['is_default' => true]);
    }
}
