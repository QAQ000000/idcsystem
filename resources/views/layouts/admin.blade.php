<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', '管理后台') - IDC System</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 text-slate-900">
    <div class="min-h-screen">
        <header class="border-b bg-white">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
                <a href="{{ route('admin.dashboard') }}" class="text-lg font-semibold">IDC 管理后台</a>
                @php($adminUser = auth('admin')->user())
                @php($canAdmin = fn (string $permission): bool => $adminUser && ($adminUser->hasRole('super-admin') || $adminUser->can($permission)))
                <nav class="flex gap-4 text-sm text-slate-600">
                    @if ($canAdmin('client.view'))
                        <a href="{{ route('admin.clients.index') }}">客户</a>
                    @endif
                    @if ($canAdmin('client.manage'))
                        <a href="{{ route('admin.client-tags.index') }}">客户标签</a>
                    @endif
                    @if ($canAdmin('client_group.manage'))
                        <a href="{{ route('admin.client-groups.index') }}">客户分组</a>
                    @endif
                    @if ($canAdmin('affiliate.view'))
                        <a href="{{ route('admin.affiliates.index') }}">分销</a>
                    @endif
                    @if ($canAdmin('export.data'))
                        <a href="{{ route('admin.export.clients') }}">导出</a>
                    @endif
                    @if ($canAdmin('import.data'))
                        <a href="{{ route('admin.imports.index') }}">导入</a>
                    @endif
                    @if ($canAdmin('gdpr.manage'))
                        <a href="{{ route('admin.gdpr.deletion-requests.index') }}">GDPR</a>
                    @endif
                    @if ($canAdmin('report.view'))
                        <a href="{{ route('admin.reports.index') }}">报表</a>
                    @endif
                    @if ($canAdmin('financial_statement.view'))
                        <a href="{{ route('admin.financial-statements.index') }}">财务对账</a>
                    @endif
                    @if ($canAdmin('product.view'))
                        <a href="{{ route('admin.products.index') }}">产品</a>
                    @endif
                    @if ($canAdmin('host.view'))
                        <a href="{{ route('admin.hosts.index') }}">服务</a>
                    @endif
                    @if ($canAdmin('order.view'))
                        <a href="{{ route('admin.orders.index') }}">订单</a>
                    @endif
                    @if ($canAdmin('cancel_request.manage'))
                        <a href="{{ route('admin.cancel-requests.index') }}">取消申请</a>
                    @endif
                    @if ($canAdmin('promo.manage'))
                        <a href="{{ route('admin.promo-codes.index') }}">优惠码</a>
                    @endif
                    @if ($canAdmin('tax_rule.view'))
                        <a href="{{ route('admin.tax-rules.index') }}">税率规则</a>
                    @endif
                    @if ($canAdmin('domain.view'))
                        <a href="{{ route('admin.domains.index') }}">域名</a>
                    @endif
                    @if ($canAdmin('domain.manage'))
                        <a href="{{ route('admin.domain-pricings.index') }}">TLD价格</a>
                    @endif
                    @if ($canAdmin('ssl.view'))
                        <a href="{{ route('admin.ssl.index') }}">SSL证书</a>
                    @endif
                    @if ($canAdmin('invoice.view'))
                        <a href="{{ route('admin.invoices.index') }}">账单</a>
                        <a href="{{ route('admin.invoice-receipts.index') }}">发票申请</a>
                    @endif
                    @if ($canAdmin('contract.manage'))
                        <a href="{{ route('admin.contract-templates.index') }}">合同模板</a>
                    @endif
                    @if ($canAdmin('announcement.manage'))
                        <a href="{{ route('admin.announcements.index') }}">公告</a>
                    @endif
                    @if ($canAdmin('kb.manage'))
                        <a href="{{ route('admin.kb.articles.index') }}">知识库</a>
                    @endif
                    @if ($canAdmin('webhook.manage'))
                        <a href="{{ route('admin.webhooks.index') }}">Webhooks</a>
                    @endif
                    @if ($canAdmin('api_doc.view'))
                        <a href="{{ route('admin.api-docs.index') }}">API文档</a>
                    @endif
                    @if ($canAdmin('ticket.view'))
                        <a href="{{ route('admin.tickets.index') }}">工单</a>
                        <a href="{{ route('admin.ticket-slas.index') }}">工单 SLA</a>
                        <a href="{{ route('admin.ticket-assignment-rules.index') }}">工单分配</a>
                    @endif
                    @if ($canAdmin('notification.manage'))
                        <a href="{{ route('admin.notifications.index') }}">通知中心</a>
                        <a href="{{ route('admin.email-logs.index') }}">邮件日志</a>
                        <a href="{{ route('admin.sms-logs.index') }}">短信日志</a>
                    @endif
                    @if ($canAdmin('campaign.view'))
                        <a href="{{ route('admin.campaigns.index') }}">邮件活动</a>
                    @endif
                    @if ($canAdmin('notification.template'))
                        <a href="{{ route('admin.email-templates.index') }}">邮件模板</a>
                        <a href="{{ route('admin.sms-templates.index') }}">短信模板</a>
                    @endif
                    @if ($canAdmin('system_task.view'))
                        <a href="{{ route('admin.system-tasks.index') }}">系统任务</a>
                        <a href="{{ url('/horizon') }}">队列监控</a>
                    @endif
                    @if ($canAdmin('backup.manage'))
                        <a href="{{ route('admin.backups.index') }}">备份</a>
                    @endif
                    @if ($canAdmin('admin_action_log.view'))
                        <a href="{{ route('admin.admin-action-logs.index') }}">后台审计</a>
                    @endif
                    @if ($canAdmin('login_attempt.view'))
                        <a href="{{ route('admin.login-attempts.index') }}">登录记录</a>
                    @endif
                    @if ($canAdmin('log.view'))
                        <a href="{{ route('admin.logs.index') }}">日志中心</a>
                    @endif
                    @if ($canAdmin('plugin.manage'))
                        <a href="{{ route('admin.plugins.index') }}">插件</a>
                    @endif
                    @if ($canAdmin('setting.manage'))
                        <a href="{{ route('admin.settings.index') }}">设置</a>
                    @endif
                    <a href="{{ route('admin.profile.2fa') }}">2FA</a>
                    <form method="post" action="{{ route('admin.logout') }}">
                        @csrf
                        <button>退出</button>
                    </form>
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-7xl px-6 py-8">
            @if (session('status'))
                <div class="mb-6 rounded border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
            @endif

            @yield('content')
        </main>
    </div>
</body>
</html>
