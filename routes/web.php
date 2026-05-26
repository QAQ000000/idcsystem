<?php

use App\Http\Controllers\Client\AuthController;
use App\Http\Controllers\Client\AccountController;
use App\Http\Controllers\Client\CartController;
use App\Http\Controllers\Client\CancelRequestController;
use App\Http\Controllers\Client\ContractController;
use App\Http\Controllers\Client\DashboardController;
use App\Http\Controllers\Client\DomainController;
use App\Http\Controllers\Client\HostController;
use App\Http\Controllers\Client\InvoiceController;
use App\Http\Controllers\Client\InvoiceReceiptController;
use App\Http\Controllers\Client\KnowledgeBaseController;
use App\Http\Controllers\Client\NotificationController;
use App\Http\Controllers\Client\ProductController;
use App\Http\Controllers\Client\PrivacyController;
use App\Http\Controllers\Client\SslController;
use App\Http\Controllers\Client\TicketController;
use App\Http\Controllers\Client\UsageAlertController;
use App\Http\Controllers\CampaignTrackingController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Install\InstallController;
use Illuminate\Support\Facades\Route;
use Plugins\Captcha\ImageCaptcha\src\CaptchaController;

Route::prefix('install')->name('install.')->group(function (): void {
    Route::get('/', [InstallController::class, 'index'])->name('index');
    Route::get('/database', [InstallController::class, 'database'])->name('database');
    Route::post('/database', [InstallController::class, 'saveDatabase'])->middleware('throttle:5,1')->name('database.save');
    Route::get('/admin', [InstallController::class, 'admin'])->name('admin');
    Route::post('/admin', [InstallController::class, 'saveAdmin'])->middleware('throttle:5,1')->name('admin.save');
    Route::get('/finish', [InstallController::class, 'finish'])->name('finish');
});

Route::redirect('/', '/products')->name('home');
Route::get('/health', [HealthController::class, 'check'])->name('health');
Route::get('/campaign/track/open/{recipient}', [CampaignTrackingController::class, 'open'])
    ->whereNumber('recipient')
    ->middleware('signed')
    ->name('campaign.track.open');
Route::get('/campaign/track/click/{recipient}/{linkId}', [CampaignTrackingController::class, 'click'])
    ->whereNumber('recipient')
    ->middleware('signed')
    ->name('campaign.track.click');

Route::get('/login', [AuthController::class, 'showLoginForm'])->name('client.login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('client.login.store');
Route::get('/login/2fa', [AuthController::class, 'showTwoFactorForm'])->name('client.login.2fa');
Route::post('/login/2fa/verify', [AuthController::class, 'verifyTwoFactor'])->middleware('throttle:10,1')->name('client.login.2fa.verify');
Route::get('/register', [AuthController::class, 'showRegisterForm'])->name('client.register');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1')->name('client.register.store');
Route::get('/oauth/wechat', [AuthController::class, 'redirectToWechatOAuth'])->middleware('throttle:10,1')->name('oauth.wechat.redirect');
Route::get('/oauth/wechat/callback', [AuthController::class, 'handleWechatOAuthCallback'])->middleware('throttle:10,1')->name('oauth.wechat.callback');
Route::get('/captcha/image/{key}', [CaptchaController::class, 'show'])
    ->where('key', '[A-Za-z0-9]{32,80}')
    ->middleware('throttle:60,1')
    ->name('captcha.image');
Route::post('/logout', [AuthController::class, 'logout'])->name('client.logout');
Route::get('/email/verify', [AuthController::class, 'verificationNotice'])
    ->middleware('auth:client')
    ->name('verification.notice');
Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
    ->middleware('signed')
    ->name('verification.verify');
Route::post('/email/resend', [AuthController::class, 'resendVerification'])
    ->middleware(['auth:client', 'throttle:6,1'])
    ->name('verification.resend');

Route::get('/products', [ProductController::class, 'index'])->name('client.products.index');
Route::get('/products/{product}', [ProductController::class, 'show'])->name('client.products.show');
Route::get('/kb', [KnowledgeBaseController::class, 'index'])->name('client.kb.index');
Route::get('/kb/search', [KnowledgeBaseController::class, 'search'])->name('client.kb.search');
Route::get('/kb/{category:slug}', [KnowledgeBaseController::class, 'category'])->name('client.kb.category');
Route::get('/kb/{category:slug}/{article:slug}', [KnowledgeBaseController::class, 'article'])->name('client.kb.article');
Route::post('/kb/{article:slug}/feedback', [KnowledgeBaseController::class, 'feedback'])->name('client.kb.feedback');
Route::post('/payment/{gateway}/callback', [InvoiceController::class, 'callback'])
    ->middleware('throttle:60,1')
    ->name('payment.callback');

Route::prefix('client')->name('client.')->middleware(['auth:client', 'client.status'])->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/account/profile', [AccountController::class, 'profile'])->name('account.profile');
    Route::put('/account/profile', [AccountController::class, 'updateProfile'])->name('account.profile.update');
    Route::get('/account/activity', [AccountController::class, 'activity'])->name('account.activity');
    Route::get('/account/security', [AccountController::class, 'security'])->name('account.security');
    Route::get('/account/notifications', [AccountController::class, 'notifications'])->name('account.notifications');
    Route::post('/account/notifications', [AccountController::class, 'updateNotifications'])->name('account.notifications.update');
    Route::get('/account/privacy', [PrivacyController::class, 'index'])->name('account.privacy');
    Route::post('/account/export-data', [PrivacyController::class, 'exportData'])->name('account.export-data');
    Route::get('/account/export-data/{request}/download', [PrivacyController::class, 'downloadExport'])->name('account.export-data.download');
    Route::post('/account/delete-account', [PrivacyController::class, 'deleteAccount'])->name('account.delete-account');
    Route::put('/account/password', [AccountController::class, 'updatePassword'])->name('account.password.update');
    Route::post('/account/2fa/enable', [AccountController::class, 'enableTwoFactor'])->name('account.2fa.enable');
    Route::post('/account/2fa/disable', [AccountController::class, 'disableTwoFactor'])->name('account.2fa.disable');
    Route::get('/account/recharge', [AccountController::class, 'recharge'])->name('account.recharge');
    Route::post('/account/recharge', [AccountController::class, 'recharge'])->name('account.recharge.store');
    Route::get('/affiliate', [AccountController::class, 'affiliate'])->name('affiliate');
    Route::post('/affiliate/withdraw', [AccountController::class, 'withdrawAffiliate'])->name('affiliate.withdraw');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');

    Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
    Route::post('/cart', [CartController::class, 'add'])->name('cart.add');
    Route::delete('/cart/{itemId}', [CartController::class, 'remove'])->whereNumber('itemId')->name('cart.remove');
    Route::post('/cart/promo', [CartController::class, 'applyPromo'])->name('cart.promo');
    Route::delete('/cart/promo', [CartController::class, 'removePromo'])->name('cart.promo.remove');
    Route::post('/cart/checkout', [CartController::class, 'checkout'])->name('cart.checkout');

    Route::get('/hosts', [HostController::class, 'index'])->name('hosts.index');
    Route::get('/hosts/{host}', [HostController::class, 'show'])->name('hosts.show');
    Route::get('/hosts/{host}/alerts', [UsageAlertController::class, 'index'])->name('hosts.alerts.index');
    Route::post('/hosts/{host}/alerts', [UsageAlertController::class, 'store'])->name('hosts.alerts.store');
    Route::delete('/hosts/{host}/alerts/{alert}', [UsageAlertController::class, 'destroy'])->name('hosts.alerts.destroy');
    Route::post('/hosts/{host}/renew', [HostController::class, 'renew'])->name('hosts.renew');
    Route::post('/hosts/{host}/upgrade', [HostController::class, 'upgrade'])->name('hosts.upgrade');
    Route::post('/hosts/{host}/addons', [HostController::class, 'addAddon'])->name('hosts.addons.store');
    Route::post('/hosts/{host}/cancel', [CancelRequestController::class, 'store'])->name('hosts.cancel');
    Route::post('/hosts/{host}/action', [HostController::class, 'action'])->name('hosts.action');

    Route::get('/domains', [DomainController::class, 'index'])->name('domains.index');
    Route::get('/domains/register', [DomainController::class, 'register'])->name('domains.register');
    Route::post('/domains/register', [DomainController::class, 'store'])->name('domains.store');
    Route::get('/domains/{domain}', [DomainController::class, 'show'])->name('domains.show');
    Route::post('/domains/{domain}/nameservers', [DomainController::class, 'updateNameservers'])->name('domains.nameservers');
    Route::post('/domains/{domain}/renew', [DomainController::class, 'renew'])->name('domains.renew');

    Route::get('/ssl', [SslController::class, 'index'])->name('ssl.index');
    Route::get('/ssl/purchase', [SslController::class, 'purchase'])->name('ssl.purchase');
    Route::post('/ssl/purchase', [SslController::class, 'store'])->name('ssl.store');
    Route::get('/ssl/{certificate}', [SslController::class, 'show'])->name('ssl.show');
    Route::post('/ssl/{certificate}/deploy', [SslController::class, 'deploy'])->name('ssl.deploy');
    Route::get('/ssl/{certificate}/download', [SslController::class, 'download'])->name('ssl.download');

    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::post('/invoices/{invoice}/pay', [InvoiceController::class, 'pay'])->name('invoices.pay');
    Route::post('/invoices/{invoice}/pay-with-credit', [InvoiceController::class, 'payWithCredit'])->name('invoices.pay-with-credit');
    Route::get('/invoices/{invoice}/receipt', [InvoiceReceiptController::class, 'create'])->name('invoices.receipt.create');
    Route::post('/invoices/{invoice}/receipt', [InvoiceReceiptController::class, 'store'])->name('invoices.receipt.store');

    Route::get('/contracts', [ContractController::class, 'index'])->name('contracts.index');
    Route::get('/contracts/{contract}', [ContractController::class, 'show'])->name('contracts.show');
    Route::post('/contracts/{contract}/sign', [ContractController::class, 'sign'])->name('contracts.sign');

    Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');
    Route::get('/tickets/create', [TicketController::class, 'create'])->name('tickets.create');
    Route::post('/tickets', [TicketController::class, 'store'])->name('tickets.store');
    Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
    Route::get('/tickets/{ticket}/attachments/{reply}/{index}', [TicketController::class, 'downloadAttachment'])
        ->whereNumber('index')
        ->name('tickets.attachments.download');
    Route::post('/tickets/{ticket}/reply', [TicketController::class, 'reply'])->name('tickets.reply');
});
