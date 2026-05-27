<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HostController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Third-party REST API
|--------------------------------------------------------------------------
| Responses use:
| success: {"success": true, "data": {...}}
| error:   {"success": false, "message": "错误描述"}
| lists:   {"success": true, "data": [...], "meta": {...}}
|
| Filters:
| GET invoices?status=Unpaid&from=2026-01-01&to=2026-12-31&per_page=20
| GET hosts?status=Active&per_page=20
| GET products?type=hosting
|
| Payments:
| POST invoices/{id}/pay-with-credit
| POST account/recharge
| GET  payment/gateways
*/

Route::middleware(['api.size', 'throttle:10,1'])->group(function (): void {
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/register', [AuthController::class, 'register']);
});

Route::middleware(['api.size', 'auth:sanctum', 'throttle:api', 'api.ip_whitelist', 'api.signature', 'api.quota', 'api.log'])->group(function (): void {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me'])->middleware('api.ability:account:read');

    Route::get('products', [ProductController::class, 'index'])->middleware('api.ability:products:read');
    Route::get('products/{product}', [ProductController::class, 'show'])->middleware('api.ability:products:read');

    Route::get('hosts', [HostController::class, 'index'])->middleware('api.ability:hosts:read');
    Route::get('hosts/{host}', [HostController::class, 'show'])->middleware('api.ability:hosts:read');
    Route::post('hosts/{host}/renew', [HostController::class, 'renew'])->middleware('api.ability:hosts:write');

    Route::get('invoices', [InvoiceController::class, 'index'])->middleware('api.ability:invoices:read');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->middleware('api.ability:invoices:read');
    Route::post('invoices/{invoice}/pay-with-credit', [InvoiceController::class, 'payWithCredit'])->middleware('api.ability:invoices:write');

    Route::get('account', [AccountController::class, 'show'])->middleware('api.ability:account:read');
    Route::get('account/credit', [AccountController::class, 'credit'])->middleware('api.ability:account:read');
    Route::post('account/recharge', [AccountController::class, 'recharge'])->middleware('api.ability:account:write');

    Route::get('payment/gateways', [PaymentController::class, 'gateways'])->middleware('api.ability:invoices:read');
});
