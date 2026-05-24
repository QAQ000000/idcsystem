<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\AdminActionLogController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EmailLogController;
use App\Http\Controllers\Admin\EmailTemplateController;
use App\Http\Controllers\Admin\HostController;
use App\Http\Controllers\Admin\InvoiceController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\NotificationCenterController;
use App\Http\Controllers\Admin\PluginController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\SystemTaskController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\SmsLogController;
use App\Http\Controllers\Admin\SmsTemplateController;
use App\Http\Controllers\Admin\TicketController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login.store');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});

Route::prefix('admin')->name('admin.')->middleware(['auth:admin', 'admin.status'])->group(function (): void {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/notifications', [NotificationCenterController::class, 'index'])->name('notifications.index');
    Route::get('/system-tasks', [SystemTaskController::class, 'index'])->name('system-tasks.index');
    Route::resource('admin-action-logs', AdminActionLogController::class)
        ->only(['index', 'show'])
        ->middleware('admin.permission:admin_action_log.view');
    Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
    Route::post('/settings', [SettingController::class, 'update'])
        ->middleware('admin.permission:setting.manage')
        ->name('settings.update');

    Route::post('/clients/{client}/credit', [ClientController::class, 'addCredit'])
        ->middleware('admin.permission:client.credit')
        ->name('clients.add-credit');
    Route::resource('clients', ClientController::class)
        ->except(['create', 'edit'])
        ->middleware(['store' => 'admin.permission:client.manage', 'update' => 'admin.permission:client.manage', 'destroy' => 'admin.permission:client.manage']);

    Route::get('/products/{product}/pricing', [ProductController::class, 'pricing'])->name('products.pricing');
    Route::post('/products/{product}/pricing', [ProductController::class, 'updatePricing'])
        ->middleware('admin.permission:product.manage')
        ->name('products.pricing.update');
    Route::resource('products', ProductController::class)
        ->middleware(['store' => 'admin.permission:product.manage', 'update' => 'admin.permission:product.manage', 'destroy' => 'admin.permission:product.manage']);

    Route::post('/orders/{order}/approve', [OrderController::class, 'approve'])
        ->middleware('admin.permission:order.approve')
        ->name('orders.approve');
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])
        ->middleware('admin.permission:order.cancel')
        ->name('orders.cancel');
    Route::resource('orders', OrderController::class)->only(['index', 'show']);

    Route::post('/hosts/{host}/action', [HostController::class, 'action'])
        ->middleware('admin.permission:host.manage')
        ->name('hosts.action');
    Route::resource('hosts', HostController::class)->only(['index', 'show']);

    Route::post('/invoices/{invoice}/paid', [InvoiceController::class, 'markPaid'])
        ->middleware('admin.permission:invoice.manage')
        ->name('invoices.mark-paid');
    Route::post('/invoices/{invoice}/refund', [InvoiceController::class, 'refund'])
        ->middleware('admin.permission:invoice.refund')
        ->name('invoices.refund');
    Route::resource('invoices', InvoiceController::class)->only(['index', 'show']);

    Route::post('/tickets/{ticket}/reply', [TicketController::class, 'reply'])
        ->middleware('admin.permission:ticket.manage')
        ->name('tickets.reply');
    Route::post('/tickets/{ticket}/assign', [TicketController::class, 'assign'])
        ->middleware('admin.permission:ticket.manage')
        ->name('tickets.assign');
    Route::post('/tickets/{ticket}/close', [TicketController::class, 'close'])
        ->middleware('admin.permission:ticket.manage')
        ->name('tickets.close');
    Route::resource('tickets', TicketController::class)->only(['index', 'show']);

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
    Route::get('/plugins/{name}/config', [PluginController::class, 'config'])->name('plugins.config');
    Route::post('/plugins/{name}/config', [PluginController::class, 'saveConfig'])
        ->middleware('admin.permission:plugin.manage')
        ->name('plugins.config.save');
    Route::resource('plugins', PluginController::class)->only(['index']);

    Route::post('/email-logs/{emailLog}/retry', [EmailLogController::class, 'retry'])
        ->middleware('admin.permission:notification.manage')
        ->name('email-logs.retry');
    Route::resource('email-logs', EmailLogController::class)->only(['index', 'show']);
    Route::get('/email-templates', [EmailTemplateController::class, 'index'])->name('email-templates.index');
    Route::get('/email-templates/{emailTemplate}/edit', [EmailTemplateController::class, 'edit'])->name('email-templates.edit');
    Route::put('/email-templates/{emailTemplate}', [EmailTemplateController::class, 'update'])
        ->middleware('admin.permission:notification.template')
        ->name('email-templates.update');

    Route::post('/sms-logs/{smsLog}/retry', [SmsLogController::class, 'retry'])
        ->middleware('admin.permission:notification.manage')
        ->name('sms-logs.retry');
    Route::resource('sms-logs', SmsLogController::class)->only(['index', 'show']);
    Route::get('/sms-templates', [SmsTemplateController::class, 'index'])->name('sms-templates.index');
    Route::get('/sms-templates/{smsTemplate}/edit', [SmsTemplateController::class, 'edit'])->name('sms-templates.edit');
    Route::put('/sms-templates/{smsTemplate}', [SmsTemplateController::class, 'update'])
        ->middleware('admin.permission:notification.template')
        ->name('sms-templates.update');
});
