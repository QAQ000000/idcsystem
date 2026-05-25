<?php

use Illuminate\Support\Facades\Route;
use Plugins\Gateway\AlipaySdk\src\NotifyController;

Route::match(['get', 'post'], '/plugin/alipay_sdk/notify', [NotifyController::class, 'handle'])
    ->name('plugin.alipay_sdk.notify')
    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);

Route::get('/plugin/alipay_sdk/return', [NotifyController::class, 'returnHandle'])
    ->name('plugin.alipay_sdk.return');
