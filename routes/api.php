<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HostController;
use App\Http\Controllers\Api\InvoiceController;
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
*/

Route::post('auth/login', [AuthController::class, 'login']);
Route::post('auth/register', [AuthController::class, 'register']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);

    Route::get('products', [ProductController::class, 'index']);
    Route::get('products/{product}', [ProductController::class, 'show']);

    Route::get('hosts', [HostController::class, 'index']);
    Route::get('hosts/{host}', [HostController::class, 'show']);
    Route::post('hosts/{host}/renew', [HostController::class, 'renew']);

    Route::get('invoices', [InvoiceController::class, 'index']);
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);

    Route::get('account', [AccountController::class, 'show']);
    Route::get('account/credit', [AccountController::class, 'credit']);
});
