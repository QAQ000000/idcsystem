@extends('layouts.admin')

@section('title', '系统设置')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">系统设置</h1>

    <form method="post" action="{{ route('admin.settings.update') }}" class="space-y-6">
        @csrf

        <section class="rounded bg-white p-6 shadow-sm">
            <h2 class="mb-4 font-semibold">基础设置</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <label class="block text-sm">
                    站点名称
                    <input class="mt-1 w-full rounded border px-3 py-2" name="site_name" value="{{ old('site_name', $settings->get('site_name', config('app.name'))) }}" required>
                </label>
                <label class="block text-sm">
                    站点 URL
                    <input class="mt-1 w-full rounded border px-3 py-2" name="site_url" value="{{ old('site_url', $settings->get('site_url', config('app.url'))) }}" required>
                </label>
                <label class="block text-sm">
                    默认货币
                    <select class="mt-1 w-full rounded border px-3 py-2" name="default_currency" required>
                        @foreach ($currencies as $currency)
                            <option value="{{ $currency->code }}" @selected(old('default_currency', $settings->get('default_currency', 'CNY')) === $currency->code)>{{ $currency->code }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="mt-7 inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" name="maintenance_mode" value="1" @checked((bool) old('maintenance_mode', $settings->get('maintenance_mode', false)))>
                    维护模式
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
                            <option value="{{ $value }}" @selected(old('auto_setup_policy', $settings->get('auto_setup_policy', 'manual')) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block text-sm">
                    账单到期天数
                    <input class="mt-1 w-full rounded border px-3 py-2" name="invoice_due_days" type="number" min="0" value="{{ old('invoice_due_days', $settings->get('invoice_due_days', 7)) }}">
                </label>
                <label class="block text-sm">
                    续费提醒天数
                    <input class="mt-1 w-full rounded border px-3 py-2" name="renewal_reminder_days" type="number" min="0" value="{{ old('renewal_reminder_days', $settings->get('renewal_reminder_days', 7)) }}">
                </label>
            </div>
        </section>

        <section class="rounded bg-white p-6 shadow-sm">
            <h2 class="mb-4 font-semibold">邮件设置</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <label class="block text-sm">
                    发件人名称
                    <input class="mt-1 w-full rounded border px-3 py-2" name="mail_from_name" value="{{ old('mail_from_name', $settings->get('mail_from_name', 'IDC System')) }}">
                </label>
                <label class="block text-sm">
                    发件人邮箱
                    <input class="mt-1 w-full rounded border px-3 py-2" name="mail_from_address" value="{{ old('mail_from_address', $settings->get('mail_from_address', 'hello@example.com')) }}">
                </label>
                <label class="block text-sm">
                    SMTP 主机
                    <input class="mt-1 w-full rounded border px-3 py-2" name="smtp_host" value="{{ old('smtp_host', $settings->get('smtp_host', '')) }}">
                </label>
                <label class="block text-sm">
                    SMTP 端口
                    <input class="mt-1 w-full rounded border px-3 py-2" name="smtp_port" type="number" value="{{ old('smtp_port', $settings->get('smtp_port', '')) }}">
                </label>
                <label class="block text-sm">
                    SMTP 用户名
                    <input class="mt-1 w-full rounded border px-3 py-2" name="smtp_username" value="{{ old('smtp_username', $settings->get('smtp_username', '')) }}">
                </label>
                <label class="block text-sm">
                    SMTP 密码
                    <input class="mt-1 w-full rounded border px-3 py-2" name="smtp_password" type="password" value="{{ old('smtp_password', $settings->get('smtp_password', '')) }}">
                </label>
            </div>
        </section>

        <section class="rounded bg-white p-6 shadow-sm">
            <h2 class="mb-4 font-semibold">短信设置</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <label class="block text-sm">
                    默认短信接口
                    <input class="mt-1 w-full rounded border px-3 py-2" name="sms_provider" value="{{ old('sms_provider', $settings->get('sms_provider', '')) }}">
                </label>
                <label class="block text-sm">
                    短信签名
                    <input class="mt-1 w-full rounded border px-3 py-2" name="sms_signature" value="{{ old('sms_signature', $settings->get('sms_signature', '')) }}">
                </label>
            </div>
        </section>

        @if ($errors->any())
            <div class="rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
        @endif

        <button class="rounded bg-slate-900 px-4 py-2 text-white">保存设置</button>
    </form>
@endsection
