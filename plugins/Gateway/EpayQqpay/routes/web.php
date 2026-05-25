<?php

use Illuminate\Support\Facades\Route;
use Plugins\Gateway\EpayQqpay\src\NotifyController;

Route::match(['get', 'post'], '/plugin/epay_qqpay/notify', [NotifyController::class, 'handle'])
    ->name('plugin.epay_qqpay.notify')
    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);

Route::get('/plugin/epay_qqpay/return', [NotifyController::class, 'returnHandle'])
    ->name('plugin.epay_qqpay.return');
