<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EmailLogController;
use App\Http\Controllers\Admin\InvoiceController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\PluginController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\SmsLogController;
use App\Http\Controllers\Admin\TicketController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.store');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});

Route::prefix('admin')->name('admin.')->middleware(['auth:admin', 'admin.status'])->group(function (): void {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
    Route::post('/settings', [SettingController::class, 'update'])->name('settings.update');

    Route::post('/clients/{client}/credit', [ClientController::class, 'addCredit'])->name('clients.add-credit');
    Route::resource('clients', ClientController::class)->except(['create', 'edit']);

    Route::get('/products/{product}/pricing', [ProductController::class, 'pricing'])->name('products.pricing');
    Route::post('/products/{product}/pricing', [ProductController::class, 'updatePricing'])->name('products.pricing.update');
    Route::resource('products', ProductController::class);

    Route::post('/orders/{order}/approve', [OrderController::class, 'approve'])->name('orders.approve');
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
    Route::resource('orders', OrderController::class)->only(['index', 'show']);

    Route::post('/invoices/{invoice}/paid', [InvoiceController::class, 'markPaid'])->name('invoices.mark-paid');
    Route::post('/invoices/{invoice}/refund', [InvoiceController::class, 'refund'])->name('invoices.refund');
    Route::resource('invoices', InvoiceController::class)->only(['index', 'show']);

    Route::post('/tickets/{ticket}/reply', [TicketController::class, 'reply'])->name('tickets.reply');
    Route::post('/tickets/{ticket}/assign', [TicketController::class, 'assign'])->name('tickets.assign');
    Route::post('/tickets/{ticket}/close', [TicketController::class, 'close'])->name('tickets.close');
    Route::resource('tickets', TicketController::class)->only(['index', 'show']);

    Route::post('/plugins/install', [PluginController::class, 'install'])->name('plugins.install');
    Route::post('/plugins/{name}/uninstall', [PluginController::class, 'uninstall'])->name('plugins.uninstall');
    Route::post('/plugins/{name}/enable', [PluginController::class, 'enable'])->name('plugins.enable');
    Route::post('/plugins/{name}/disable', [PluginController::class, 'disable'])->name('plugins.disable');
    Route::get('/plugins/{name}/config', [PluginController::class, 'config'])->name('plugins.config');
    Route::post('/plugins/{name}/config', [PluginController::class, 'saveConfig'])->name('plugins.config.save');
    Route::resource('plugins', PluginController::class)->only(['index']);

    Route::post('/email-logs/{emailLog}/retry', [EmailLogController::class, 'retry'])->name('email-logs.retry');
    Route::resource('email-logs', EmailLogController::class)->only(['index', 'show']);

    Route::post('/sms-logs/{smsLog}/retry', [SmsLogController::class, 'retry'])->name('sms-logs.retry');
    Route::resource('sms-logs', SmsLogController::class)->only(['index', 'show']);
});
