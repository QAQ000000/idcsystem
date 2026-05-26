<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\AdminActionLogController;
use App\Http\Controllers\Admin\AffiliateController;
use App\Http\Controllers\Admin\AnnouncementController;
use App\Http\Controllers\Admin\BackupController;
use App\Http\Controllers\Admin\CancelRequestController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\ClientGroupController;
use App\Http\Controllers\Admin\ContractTemplateController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DomainController;
use App\Http\Controllers\Admin\DomainPricingController;
use App\Http\Controllers\Admin\EmailLogController;
use App\Http\Controllers\Admin\EmailCampaignController;
use App\Http\Controllers\Admin\EmailTemplateController;
use App\Http\Controllers\Admin\ExportController;
use App\Http\Controllers\Admin\GdprDeletionRequestController;
use App\Http\Controllers\Admin\HostController;
use App\Http\Controllers\Admin\InvoiceController;
use App\Http\Controllers\Admin\InvoiceReceiptController;
use App\Http\Controllers\Admin\KbArticleController;
use App\Http\Controllers\Admin\KbCategoryController;
use App\Http\Controllers\Admin\LogController;
use App\Http\Controllers\Admin\LoginAttemptController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\PromoCodeController;
use App\Http\Controllers\Admin\NotificationCenterController;
use App\Http\Controllers\Admin\PluginController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductAddonController;
use App\Http\Controllers\Admin\ProductCustomFieldController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SystemTaskController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\SmsLogController;
use App\Http\Controllers\Admin\SmsTemplateController;
use App\Http\Controllers\Admin\SslController;
use App\Http\Controllers\Admin\TaxRuleController;
use App\Http\Controllers\Admin\TicketController;
use App\Http\Controllers\Admin\WebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login.store');
    Route::get('/login/2fa', [AuthController::class, 'showTwoFactor'])->name('login.2fa');
    Route::post('/login/2fa', [AuthController::class, 'verifyTwoFactor'])->middleware('throttle:10,1')->name('login.2fa.verify');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});

Route::prefix('admin')->name('admin.')->middleware(['auth:admin', 'admin.status'])->group(function (): void {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/profile/2fa', [AuthController::class, 'twoFactorSetup'])->name('profile.2fa');
    Route::post('/profile/2fa/enable', [AuthController::class, 'enableTwoFactor'])->name('profile.2fa.enable');
    Route::post('/profile/2fa/disable', [AuthController::class, 'disableTwoFactor'])->name('profile.2fa.disable');
    Route::get('/notifications', [NotificationCenterController::class, 'index'])
        ->middleware('admin.permission:notification.manage')
        ->name('notifications.index');
    Route::get('/system-tasks', [SystemTaskController::class, 'index'])
        ->middleware('admin.permission:system_task.view')
        ->name('system-tasks.index');
    Route::post('/system-tasks/run', [SystemTaskController::class, 'runManual'])
        ->middleware('admin.permission:system_task.view')
        ->name('system-tasks.run');
    Route::get('/backups', [BackupController::class, 'index'])
        ->middleware('admin.permission:backup.manage')
        ->name('backups.index');
    Route::post('/backups/database', [BackupController::class, 'database'])
        ->middleware('admin.permission:backup.manage')
        ->name('backups.database');
    Route::post('/backups/files', [BackupController::class, 'files'])
        ->middleware('admin.permission:backup.manage')
        ->name('backups.files');
    Route::get('/backups/{backup}/download', [BackupController::class, 'download'])
        ->middleware('admin.permission:backup.manage')
        ->name('backups.download');
    Route::post('/backups/{backup}/restore', [BackupController::class, 'restore'])
        ->middleware('admin.permission:backup.manage')
        ->name('backups.restore');
    Route::delete('/backups/{backup}', [BackupController::class, 'destroy'])
        ->middleware('admin.permission:backup.manage')
        ->name('backups.destroy');
    Route::resource('admin-action-logs', AdminActionLogController::class)
        ->only(['index', 'show'])
        ->middleware('admin.permission:admin_action_log.view');
    Route::get('/login-attempts', [LoginAttemptController::class, 'index'])
        ->middleware('admin.permission:login_attempt.view')
        ->name('login-attempts.index');
    Route::get('/logs', [LogController::class, 'index'])
        ->middleware('admin.permission:log.view')
        ->name('logs.index');
    Route::post('/logs/cleanup', [LogController::class, 'cleanup'])
        ->middleware('admin.permission:log.manage')
        ->name('logs.cleanup');
    Route::get('/logs/{type}', [LogController::class, 'show'])
        ->middleware('admin.permission:log.view')
        ->name('logs.show');
    Route::get('/affiliates', [AffiliateController::class, 'index'])
        ->middleware('admin.permission:affiliate.view')
        ->name('affiliates.index');
    Route::put('/affiliates/{affiliate}', [AffiliateController::class, 'update'])
        ->middleware('admin.permission:affiliate.manage')
        ->name('affiliates.update');
    Route::post('/affiliates/{affiliate}/payout', [AffiliateController::class, 'payout'])
        ->middleware('admin.permission:affiliate.manage')
        ->name('affiliates.payout');
    Route::post('/affiliate-commissions/{commission}/approve', [AffiliateController::class, 'approve'])
        ->middleware('admin.permission:affiliate.manage')
        ->name('affiliate-commissions.approve');
    Route::resource('contract-templates', ContractTemplateController::class)
        ->except(['show'])
        ->middleware('admin.permission:contract.manage');
    Route::post('/announcements/{announcement}/toggle', [AnnouncementController::class, 'toggle'])
        ->middleware('admin.permission:announcement.manage')
        ->name('announcements.toggle');
    Route::resource('announcements', AnnouncementController::class)
        ->except(['show'])
        ->middleware('admin.permission:announcement.manage');
    Route::get('/webhooks/{webhook}/deliveries', [WebhookController::class, 'deliveries'])
        ->middleware('admin.permission:webhook.manage')
        ->name('webhooks.deliveries');
    Route::post('/webhooks/{webhook}/test', [WebhookController::class, 'test'])
        ->middleware('admin.permission:webhook.manage')
        ->name('webhooks.test');
    Route::resource('webhooks', WebhookController::class)
        ->except(['show'])
        ->middleware('admin.permission:webhook.manage');
    Route::prefix('kb')->name('kb.')->middleware('admin.permission:kb.manage')->group(function (): void {
        Route::resource('categories', KbCategoryController::class)->except(['show']);
        Route::resource('articles', KbArticleController::class)->except(['show']);
    });
    Route::get('/settings', [SettingController::class, 'index'])
        ->middleware('admin.permission:setting.manage')
        ->name('settings.index');
    Route::post('/settings', [SettingController::class, 'update'])
        ->middleware('admin.permission:setting.manage')
        ->name('settings.update');

    Route::prefix('export')->name('export.')->middleware('admin.permission:export.data')->group(function (): void {
        Route::get('clients', [ExportController::class, 'clients'])->name('clients');
        Route::get('invoices', [ExportController::class, 'invoices'])->name('invoices');
        Route::get('hosts', [ExportController::class, 'hosts'])->name('hosts');
        Route::get('credits', [ExportController::class, 'credits'])->name('credits');
    });

    Route::view('/api-docs', 'admin.api-docs')
        ->middleware('admin.permission:api_doc.view')
        ->name('api-docs.index');

    Route::prefix('gdpr')->name('gdpr.')->middleware('admin.permission:gdpr.manage')->group(function (): void {
        Route::get('deletion-requests', [GdprDeletionRequestController::class, 'index'])->name('deletion-requests.index');
        Route::post('deletion-requests/{request}/approve', [GdprDeletionRequestController::class, 'approve'])->name('deletion-requests.approve');
        Route::post('deletion-requests/{request}/reject', [GdprDeletionRequestController::class, 'reject'])->name('deletion-requests.reject');
    });

    Route::prefix('reports')->name('reports.')->middleware('admin.permission:report.view')->group(function (): void {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::get('revenue', [ReportController::class, 'revenue'])->name('revenue');
        Route::get('clients', [ReportController::class, 'clients'])->name('clients');
        Route::get('hosts', [ReportController::class, 'hosts'])->name('hosts');
        Route::get('products', [ReportController::class, 'products'])->name('products');
    });

    Route::post('/clients/bulk-action', [ClientController::class, 'bulkAction'])
        ->middleware('admin.permission:client.manage')
        ->name('clients.bulk-action');
    Route::post('/clients/{client}/credit', [ClientController::class, 'addCredit'])
        ->middleware('admin.permission:client.credit')
        ->name('clients.add-credit');
    Route::post('/clients/{client}/credit-limit', [ClientController::class, 'updateCreditLimit'])
        ->middleware('admin.permission:client.credit')
        ->name('clients.credit-limit');
    Route::post('/clients/{client}/unlock', [ClientController::class, 'unlock'])
        ->middleware('admin.permission:client.manage')
        ->name('clients.unlock');
    Route::get('/clients', [ClientController::class, 'index'])
        ->middleware('admin.permission:client.view')
        ->name('clients.index');
    Route::get('/clients/{client}', [ClientController::class, 'show'])
        ->middleware('admin.permission:client.view')
        ->name('clients.show');
    Route::post('/clients', [ClientController::class, 'store'])
        ->middleware('admin.permission:client.manage')
        ->name('clients.store');
    Route::put('/clients/{client}', [ClientController::class, 'update'])
        ->middleware('admin.permission:client.manage')
        ->name('clients.update');
    Route::delete('/clients/{client}', [ClientController::class, 'destroy'])
        ->middleware('admin.permission:client.manage')
        ->name('clients.destroy');
    Route::resource('client-groups', ClientGroupController::class)
        ->except(['show'])
        ->middleware('admin.permission:client_group.manage');

    Route::get('/products/{product}/pricing', [ProductController::class, 'pricing'])
        ->middleware('admin.permission:product.view')
        ->name('products.pricing');
    Route::post('/products/{product}/pricing', [ProductController::class, 'updatePricing'])
        ->middleware('admin.permission:product.manage')
        ->name('products.pricing.update');
    Route::post('/products/{product}/custom-fields', [ProductCustomFieldController::class, 'store'])
        ->middleware('admin.permission:product.manage')
        ->name('products.custom-fields.store');
    Route::put('/products/{product}/custom-fields/{customField}', [ProductCustomFieldController::class, 'update'])
        ->middleware('admin.permission:product.manage')
        ->name('products.custom-fields.update');
    Route::delete('/products/{product}/custom-fields/{customField}', [ProductCustomFieldController::class, 'destroy'])
        ->middleware('admin.permission:product.manage')
        ->name('products.custom-fields.destroy');
    Route::get('/products/{product}/addons', [ProductAddonController::class, 'index'])
        ->middleware('admin.permission:product.manage')
        ->name('products.addons.index');
    Route::post('/products/{product}/addons', [ProductAddonController::class, 'store'])
        ->middleware('admin.permission:product.manage')
        ->name('products.addons.store');
    Route::put('/products/{product}/addons/{addon}', [ProductAddonController::class, 'update'])
        ->middleware('admin.permission:product.manage')
        ->name('products.addons.update');
    Route::delete('/products/{product}/addons/{addon}', [ProductAddonController::class, 'destroy'])
        ->middleware('admin.permission:product.manage')
        ->name('products.addons.destroy');
    Route::get('/products', [ProductController::class, 'index'])
        ->middleware('admin.permission:product.view')
        ->name('products.index');
    Route::get('/products/create', [ProductController::class, 'create'])
        ->middleware('admin.permission:product.manage')
        ->name('products.create');
    Route::get('/products/{product}', [ProductController::class, 'show'])
        ->middleware('admin.permission:product.view')
        ->name('products.show');
    Route::get('/products/{product}/edit', [ProductController::class, 'edit'])
        ->middleware('admin.permission:product.manage')
        ->name('products.edit');
    Route::post('/products', [ProductController::class, 'store'])
        ->middleware('admin.permission:product.manage')
        ->name('products.store');
    Route::put('/products/{product}', [ProductController::class, 'update'])
        ->middleware('admin.permission:product.manage')
        ->name('products.update');
    Route::delete('/products/{product}', [ProductController::class, 'destroy'])
        ->middleware('admin.permission:product.manage')
        ->name('products.destroy');

    Route::post('/orders/{order}/approve', [OrderController::class, 'approve'])
        ->middleware('admin.permission:order.approve')
        ->name('orders.approve');
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])
        ->middleware('admin.permission:order.cancel')
        ->name('orders.cancel');
    Route::resource('orders', OrderController::class)
        ->only(['index', 'show'])
        ->middleware('admin.permission:order.view');

    Route::get('/cancel-requests', [CancelRequestController::class, 'index'])
        ->middleware('admin.permission:cancel_request.manage')
        ->name('cancel-requests.index');
    Route::post('/cancel-requests/{cancelRequest}/approve', [CancelRequestController::class, 'approve'])
        ->middleware('admin.permission:cancel_request.manage')
        ->name('cancel-requests.approve');
    Route::post('/cancel-requests/{cancelRequest}/reject', [CancelRequestController::class, 'reject'])
        ->middleware('admin.permission:cancel_request.manage')
        ->name('cancel-requests.reject');

    Route::post('/promo-codes/{promoCode}/toggle', [PromoCodeController::class, 'toggle'])
        ->middleware('admin.permission:promo.manage')
        ->name('promo-codes.toggle');
    Route::resource('promo-codes', PromoCodeController::class)
        ->except(['show'])
        ->middleware('admin.permission:promo.manage');
    Route::get('/tax-rules', [TaxRuleController::class, 'index'])
        ->middleware('admin.permission:tax_rule.view')
        ->name('tax-rules.index');
    Route::get('/tax-rules/create', [TaxRuleController::class, 'create'])
        ->middleware('admin.permission:tax_rule.manage')
        ->name('tax-rules.create');
    Route::post('/tax-rules', [TaxRuleController::class, 'store'])
        ->middleware('admin.permission:tax_rule.manage')
        ->name('tax-rules.store');
    Route::get('/tax-rules/{taxRule}/edit', [TaxRuleController::class, 'edit'])
        ->middleware('admin.permission:tax_rule.manage')
        ->name('tax-rules.edit');
    Route::put('/tax-rules/{taxRule}', [TaxRuleController::class, 'update'])
        ->middleware('admin.permission:tax_rule.manage')
        ->name('tax-rules.update');
    Route::delete('/tax-rules/{taxRule}', [TaxRuleController::class, 'destroy'])
        ->middleware('admin.permission:tax_rule.manage')
        ->name('tax-rules.destroy');

    Route::get('/domains', [DomainController::class, 'index'])
        ->middleware('admin.permission:domain.view')
        ->name('domains.index');
    Route::get('/domain-pricings', [DomainPricingController::class, 'index'])
        ->middleware('admin.permission:domain.manage')
        ->name('domain-pricings.index');
    Route::post('/domain-pricings', [DomainPricingController::class, 'store'])
        ->middleware('admin.permission:domain.manage')
        ->name('domain-pricings.store');
    Route::put('/domain-pricings/{domainPricing}', [DomainPricingController::class, 'update'])
        ->middleware('admin.permission:domain.manage')
        ->name('domain-pricings.update');
    Route::get('/ssl', [SslController::class, 'index'])
        ->middleware('admin.permission:ssl.view')
        ->name('ssl.index');
    Route::post('/ssl/{certificate}/issue', [SslController::class, 'issue'])
        ->middleware('admin.permission:ssl.manage')
        ->name('ssl.issue');

    Route::get('/campaigns', [EmailCampaignController::class, 'index'])
        ->middleware('admin.permission:campaign.view')
        ->name('campaigns.index');
    Route::get('/campaigns/create', [EmailCampaignController::class, 'create'])
        ->middleware('admin.permission:campaign.manage')
        ->name('campaigns.create');
    Route::post('/campaigns', [EmailCampaignController::class, 'store'])
        ->middleware('admin.permission:campaign.manage')
        ->name('campaigns.store');
    Route::get('/campaigns/{campaign}', [EmailCampaignController::class, 'show'])
        ->middleware('admin.permission:campaign.view')
        ->name('campaigns.show');
    Route::post('/campaigns/{campaign}/send', [EmailCampaignController::class, 'send'])
        ->middleware('admin.permission:campaign.manage')
        ->name('campaigns.send');
    Route::post('/campaigns/{campaign}/schedule', [EmailCampaignController::class, 'schedule'])
        ->middleware('admin.permission:campaign.manage')
        ->name('campaigns.schedule');

    Route::post('/hosts/bulk-action', [HostController::class, 'bulkAction'])
        ->middleware('admin.permission:host.manage')
        ->name('hosts.bulk-action');
    Route::post('/hosts/{host}/action', [HostController::class, 'action'])
        ->middleware('admin.permission:host.manage')
        ->name('hosts.action');
    Route::resource('hosts', HostController::class)
        ->only(['index', 'show'])
        ->middleware('admin.permission:host.view');

    Route::post('/invoices/{invoice}/paid', [InvoiceController::class, 'markPaid'])
        ->middleware('admin.permission:invoice.manage')
        ->name('invoices.mark-paid');
    Route::post('/invoices/{invoice}/refund', [InvoiceController::class, 'refund'])
        ->middleware('admin.permission:invoice.refund')
        ->name('invoices.refund');
    Route::resource('invoices', InvoiceController::class)
        ->only(['index', 'show'])
        ->middleware('admin.permission:invoice.view');
    Route::get('/invoice-receipts', [InvoiceReceiptController::class, 'index'])
        ->middleware('admin.permission:invoice.view')
        ->name('invoice-receipts.index');
    Route::put('/invoice-receipts/{receipt}', [InvoiceReceiptController::class, 'update'])
        ->middleware('admin.permission:invoice.manage')
        ->name('invoice-receipts.update');

    Route::post('/tickets/{ticket}/reply', [TicketController::class, 'reply'])
        ->middleware('admin.permission:ticket.manage')
        ->name('tickets.reply');
    Route::get('/tickets/{ticket}/attachments/{reply}/{index}', [TicketController::class, 'downloadAttachment'])
        ->whereNumber('index')
        ->middleware('admin.permission:ticket.view')
        ->name('tickets.attachments.download');
    Route::post('/tickets/{ticket}/assign', [TicketController::class, 'assign'])
        ->middleware('admin.permission:ticket.manage')
        ->name('tickets.assign');
    Route::post('/tickets/{ticket}/close', [TicketController::class, 'close'])
        ->middleware('admin.permission:ticket.manage')
        ->name('tickets.close');
    Route::resource('tickets', TicketController::class)
        ->only(['index', 'show'])
        ->middleware('admin.permission:ticket.view');

    Route::post('/plugins/install', [PluginController::class, 'install'])
        ->middleware('admin.permission:plugin.manage')
        ->name('plugins.install');
    Route::post('/plugins/{name}/uninstall', [PluginController::class, 'uninstall'])
        ->middleware('admin.permission:plugin.manage')
        ->name('plugins.uninstall');
    Route::post('/plugins/{name}/enable', [PluginController::class, 'enable'])
        ->middleware('admin.permission:plugin.manage')
        ->name('plugins.enable');
    Route::post('/plugins/{name}/disable', [PluginController::class, 'disable'])
        ->middleware('admin.permission:plugin.manage')
        ->name('plugins.disable');
    Route::get('/plugins/{name}/config', [PluginController::class, 'config'])
        ->middleware('admin.permission:plugin.manage')
        ->name('plugins.config');
    Route::post('/plugins/{name}/config', [PluginController::class, 'saveConfig'])
        ->middleware('admin.permission:plugin.manage')
        ->name('plugins.config.save');
    Route::resource('plugins', PluginController::class)
        ->only(['index'])
        ->middleware('admin.permission:plugin.manage');

    Route::post('/email-logs/{emailLog}/retry', [EmailLogController::class, 'retry'])
        ->middleware('admin.permission:notification.manage')
        ->name('email-logs.retry');
    Route::resource('email-logs', EmailLogController::class)
        ->only(['index', 'show'])
        ->middleware('admin.permission:notification.manage');
    Route::get('/email-templates', [EmailTemplateController::class, 'index'])
        ->middleware('admin.permission:notification.template')
        ->name('email-templates.index');
    Route::get('/email-templates/{emailTemplate}/edit', [EmailTemplateController::class, 'edit'])
        ->middleware('admin.permission:notification.template')
        ->name('email-templates.edit');
    Route::get('/email-templates/{emailTemplate}/preview', [EmailTemplateController::class, 'preview'])
        ->middleware('admin.permission:notification.template')
        ->name('email-templates.preview');
    Route::post('/email-templates/{emailTemplate}/test', [EmailTemplateController::class, 'test'])
        ->middleware('admin.permission:notification.template')
        ->name('email-templates.test');
    Route::put('/email-templates/{emailTemplate}', [EmailTemplateController::class, 'update'])
        ->middleware('admin.permission:notification.template')
        ->name('email-templates.update');

    Route::post('/sms-logs/{smsLog}/retry', [SmsLogController::class, 'retry'])
        ->middleware('admin.permission:notification.manage')
        ->name('sms-logs.retry');
    Route::resource('sms-logs', SmsLogController::class)
        ->only(['index', 'show'])
        ->middleware('admin.permission:notification.manage');
    Route::get('/sms-templates', [SmsTemplateController::class, 'index'])
        ->middleware('admin.permission:notification.template')
        ->name('sms-templates.index');
    Route::get('/sms-templates/{smsTemplate}/edit', [SmsTemplateController::class, 'edit'])
        ->middleware('admin.permission:notification.template')
        ->name('sms-templates.edit');
    Route::get('/sms-templates/{smsTemplate}/preview', [SmsTemplateController::class, 'preview'])
        ->middleware('admin.permission:notification.template')
        ->name('sms-templates.preview');
    Route::post('/sms-templates/{smsTemplate}/test', [SmsTemplateController::class, 'test'])
        ->middleware('admin.permission:notification.template')
        ->name('sms-templates.test');
    Route::put('/sms-templates/{smsTemplate}', [SmsTemplateController::class, 'update'])
        ->middleware('admin.permission:notification.template')
        ->name('sms-templates.update');
});
