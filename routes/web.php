<?php

use App\Http\Controllers\Client\AuthController;
use App\Http\Controllers\Client\AccountController;
use App\Http\Controllers\Client\CartController;
use App\Http\Controllers\Client\DashboardController;
use App\Http\Controllers\Client\HostController;
use App\Http\Controllers\Client\InvoiceController;
use App\Http\Controllers\Client\ProductController;
use App\Http\Controllers\Client\TicketController;
use App\Http\Controllers\Install\InstallController;
use Illuminate\Support\Facades\Route;

Route::prefix('install')->name('install.')->group(function (): void {
    Route::get('/', [InstallController::class, 'index'])->name('index');
    Route::get('/database', [InstallController::class, 'database'])->name('database');
    Route::post('/database', [InstallController::class, 'saveDatabase'])->middleware('throttle:5,1')->name('database.save');
    Route::get('/admin', [InstallController::class, 'admin'])->name('admin');
    Route::post('/admin', [InstallController::class, 'saveAdmin'])->middleware('throttle:5,1')->name('admin.save');
    Route::get('/finish', [InstallController::class, 'finish'])->name('finish');
});

Route::get('/', fn () => redirect()->route('client.products.index'))->name('home');

Route::get('/login', [AuthController::class, 'showLoginForm'])->name('client.login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('client.login.store');
Route::get('/register', [AuthController::class, 'showRegisterForm'])->name('client.register');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1')->name('client.register.store');
Route::post('/logout', [AuthController::class, 'logout'])->name('client.logout');

Route::get('/products', [ProductController::class, 'index'])->name('client.products.index');
Route::get('/products/{product}', [ProductController::class, 'show'])->name('client.products.show');

Route::prefix('client')->name('client.')->middleware(['auth:client', 'client.status'])->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/account/profile', [AccountController::class, 'profile'])->name('account.profile');
    Route::put('/account/profile', [AccountController::class, 'updateProfile'])->name('account.profile.update');
    Route::get('/account/security', [AccountController::class, 'security'])->name('account.security');
    Route::put('/account/password', [AccountController::class, 'updatePassword'])->name('account.password.update');

    Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
    Route::post('/cart', [CartController::class, 'add'])->name('cart.add');
    Route::delete('/cart/{itemId}', [CartController::class, 'remove'])->name('cart.remove');
    Route::post('/cart/checkout', [CartController::class, 'checkout'])->name('cart.checkout');

    Route::get('/hosts', [HostController::class, 'index'])->name('hosts.index');
    Route::get('/hosts/{host}', [HostController::class, 'show'])->name('hosts.show');
    Route::post('/hosts/{host}/renew', [HostController::class, 'renew'])->name('hosts.renew');
    Route::post('/hosts/{host}/upgrade', [HostController::class, 'upgrade'])->name('hosts.upgrade');
    Route::post('/hosts/{host}/action', [HostController::class, 'action'])->name('hosts.action');

    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::post('/invoices/{invoice}/pay', [InvoiceController::class, 'pay'])->name('invoices.pay');

    Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');
    Route::get('/tickets/create', [TicketController::class, 'create'])->name('tickets.create');
    Route::post('/tickets', [TicketController::class, 'store'])->name('tickets.store');
    Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
    Route::post('/tickets/{ticket}/reply', [TicketController::class, 'reply'])->name('tickets.reply');
});
