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

Route::middleware('throttle:10,1')->group(function (): void {
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/register', [AuthController::class, 'register']);
});

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);

    Route::get('products', [ProductController::class, 'index']);
    Route::get('products/{product}', [ProductController::class, 'show']);

    Route::get('hosts', [HostController::class, 'index']);
    Route::get('hosts/{host}', [HostController::class, 'show']);
    Route::post('hosts/{host}/renew', [HostController::class, 'renew']);

    Route::get('invoices', [InvoiceController::class, 'index']);
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);
    Route::post('invoices/{invoice}/pay-with-credit', [InvoiceController::class, 'payWithCredit']);

    Route::get('account', [AccountController::class, 'show']);
    Route::get('account/credit', [AccountController::class, 'credit']);
    Route::post('account/recharge', [AccountController::class, 'recharge']);

    Route::get('payment/gateways', [PaymentController::class, 'gateways']);
});
