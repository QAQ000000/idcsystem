@extends('layouts.admin')

@section('title', '系统设置')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">系统设置</h1>

    @php
        $oldText = function (string $key, mixed $default = '') {
            $value = old($key, $default);

            return is_scalar($value) || $value === null ? $value : $default;
        };
    @endphp

    <form method="post" action="{{ route('admin.settings.update') }}" class="space-y-6">
        @csrf

        <section class="rounded bg-white p-6 shadow-sm">
            <h2 class="mb-4 font-semibold">基础设置</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <label class="block text-sm">
                    站点名称
                    <input class="mt-1 w-full rounded border px-3 py-2" name="site_name" value="{{ $oldText('site_name', $settings->get('site_name', config('app.name'))) }}" required>
                </label>
                <label class="block text-sm">
                    站点 URL
                    <input class="mt-1 w-full rounded border px-3 py-2" name="site_url" value="{{ $oldText('site_url', $settings->get('site_url', config('app.url'))) }}" required>
                </label>
                <label class="block text-sm">
                    默认货币
                    <select class="mt-1 w-full rounded border px-3 py-2" name="default_currency" required>
                        @foreach ($currencies as $currency)
                            <option value="{{ $currency->code }}" @selected($oldText('default_currency', $settings->get('default_currency', 'CNY')) === $currency->code)>{{ $currency->code }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block text-sm">
                    前台主题
                    <select class="mt-1 w-full rounded border px-3 py-2" name="theme">
                        @foreach ($themes as $theme)
                            <option value="{{ $theme }}" @selected($oldText('theme', $settings->get('theme', 'default')) === $theme)>{{ $theme }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="mt-7 inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" name="maintenance_mode" value="1" @checked((bool) $oldText('maintenance_mode', $settings->get('maintenance_mode', false)))>
                    维护模式
                </label>
                <label class="mt-7 inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" name="captcha_enabled" value="1" @checked((bool) $oldText('captcha_enabled', $settings->get('captcha_enabled', false)))>
                    登录/注册图形验证码
                </label>
            </div>
        </section>

        <section class="rounded bg-white p-6 shadow-sm">
            <h2 class="mb-4 font-semibold">订单设置</h2>
            <div class="grid gap-4 md:grid-cols-3">
                <label class="block text-sm">
                    自动开通策略
                    <select class="mt-1 w-full rounded border px-3 py-2" name="auto_setup_policy">
                        @foreach (['manual' => '人工审核', 'paid' => '付款后开通', 'instant' => '下单即开通'] as $value => $label)
                            <option value="{{ $value }}" @selected($oldText('auto_setup_policy', $settings->get('auto_setup_policy', 'manual')) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block text-sm">
                    账单到期天数
                    <input class="mt-1 w-full rounded border px-3 py-2" name="invoice_due_days" type="number" min="0" value="{{ $oldText('invoice_due_days', $settings->get('invoice_due_days', 7)) }}">
                </label>
                <label class="block text-sm">
                    续费提醒天数
                    <input class="mt-1 w-full rounded border px-3 py-2" name="renewal_reminder_days" type="number" min="0" value="{{ $oldText('renewal_reminder_days', $settings->get('renewal_reminder_days', 7)) }}">
                </label>
            </div>
        </section>

        <section class="rounded bg-white p-6 shadow-sm">
            <h2 class="mb-4 font-semibold">账务设置</h2>
            <div class="grid gap-4 md:grid-cols-3">
                <label class="block text-sm">
                    税率（%）
                    <input class="mt-1 w-full rounded border px-3 py-2" name="billing_tax_rate" type="number" min="0" max="100" step="0.01" value="{{ $oldText('billing_tax_rate', $settings->get('billing_tax_rate', config('billing.tax_rate', 0))) }}">
                </label>
                <label class="block text-sm">
                    逾期宽限天数
                    <input class="mt-1 w-full rounded border px-3 py-2" name="billing_grace_days" type="number" min="0" value="{{ $oldText('billing_grace_days', $settings->get('billing_grace_days', config('billing.grace_days', 0))) }}">
                </label>
                <label class="block text-sm">
                    续费账单提前生成天数
                    <input class="mt-1 w-full rounded border px-3 py-2" name="billing_invoice_days_before_due" type="number" min="0" value="{{ $oldText('billing_invoice_days_before_due', $settings->get('billing_invoice_days_before_due', config('billing.invoice_days_before_due', 7))) }}">
                </label>
            </div>
        </section>

        <section class="rounded bg-white p-6 shadow-sm">
            <h2 class="mb-4 font-semibold">邮件设置</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <label class="block text-sm">
                    默认邮件插件
                    <input class="mt-1 w-full rounded border px-3 py-2" name="default_email_provider" value="{{ $oldText('default_email_provider', $settings->get('default_email_provider', 'smtp')) }}">
                </label>
                <label class="mt-7 inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" name="mail_queue_enabled" value="1" @checked((bool) $oldText('mail_queue_enabled', $settings->get('mail_queue_enabled', false)))>
                    邮件异步发送
                </label>
                <label class="block text-sm">
                    发件人名称
                    <input class="mt-1 w-full rounded border px-3 py-2" name="mail_from_name" value="{{ $oldText('mail_from_name', $settings->get('mail_from_name', 'IDC System')) }}">
                </label>
                <label class="block text-sm">
                    发件人邮箱
                    <input class="mt-1 w-full rounded border px-3 py-2" name="mail_from_address" value="{{ $oldText('mail_from_address', $settings->get('mail_from_address', 'hello@example.com')) }}">
                </label>
                <label class="block text-sm">
                    SMTP 主机
                    <input class="mt-1 w-full rounded border px-3 py-2" name="smtp_host" value="{{ $oldText('smtp_host', $settings->get('smtp_host', '')) }}">
                </label>
                <label class="block text-sm">
                    SMTP 端口
                    <input class="mt-1 w-full rounded border px-3 py-2" name="smtp_port" type="number" value="{{ $oldText('smtp_port', $settings->get('smtp_port', '')) }}">
                </label>
                <label class="block text-sm">
                    SMTP 加密方式
                    <select class="mt-1 w-full rounded border px-3 py-2" name="smtp_encryption">
                        @foreach (['' => '无', 'tls' => 'TLS', 'ssl' => 'SSL'] as $value => $label)
                            <option value="{{ $value }}" @selected($oldText('smtp_encryption', $settings->get('smtp_encryption', '')) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block text-sm">
                    SMTP 用户名
                    <input class="mt-1 w-full rounded border px-3 py-2" name="smtp_username" value="{{ $oldText('smtp_username', $settings->get('smtp_username', '')) }}">
                </label>
                <label class="block text-sm">
                    SMTP 密码
                    <input class="mt-1 w-full rounded border px-3 py-2" name="smtp_password" type="password" value="{{ $oldText('smtp_password', '') }}" placeholder="{{ $settings->get('smtp_password') ? '已保存，留空则不修改' : '' }}" autocomplete="new-password">
                </label>
            </div>
        </section>

        <section class="rounded bg-white p-6 shadow-sm">
            <h2 class="mb-4 font-semibold">短信设置</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <label class="block text-sm">
                    默认短信接口
                    <input class="mt-1 w-full rounded border px-3 py-2" name="default_sms_provider" value="{{ $oldText('default_sms_provider', $settings->get('default_sms_provider', 'aliyun')) }}">
                </label>
                <label class="mt-7 inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" name="sms_queue_enabled" value="1" @checked((bool) $oldText('sms_queue_enabled', $settings->get('sms_queue_enabled', false)))>
                    短信异步发送
                </label>
                <label class="block text-sm">
                    短信签名
                    <input class="mt-1 w-full rounded border px-3 py-2" name="sms_signature" value="{{ $oldText('sms_signature', $settings->get('sms_signature', '')) }}">
                </label>
            </div>
        </section>

        <section class="rounded bg-white p-6 shadow-sm">
            <h2 class="mb-4 font-semibold">通知设置</h2>
            <div class="grid gap-4 md:grid-cols-2 text-sm">
                @foreach ($notificationEvents as $key => $event)
                    <div class="rounded border p-4">
                        <div class="mb-3 font-medium">{{ $event['label'] }}</div>
                        <label class="mr-4 inline-flex items-center gap-2">
                            <input type="checkbox" name="notify_{{ $key }}_mail" value="1" @checked((bool) $oldText('notify_' . $key . '_mail', $settings->get('notify_' . $key . '_mail', true)))>
                            邮件
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" name="notify_{{ $key }}_sms" value="1" @checked((bool) $oldText('notify_' . $key . '_sms', $settings->get('notify_' . $key . '_sms', true)))>
                            短信
                        </label>
                    </div>
                @endforeach
            </div>
        </section>

        @if ($errors->any())
            <div class="rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
        @endif

        <button class="rounded bg-slate-900 px-4 py-2 text-white">保存设置</button>
    </form>
@endsection
