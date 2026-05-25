<?php

use Illuminate\Support\Facades\Route;
use Plugins\Gateway\EpayAlipay\src\NotifyController;

Route::match(['get', 'post'], '/plugin/epay_alipay/notify', [NotifyController::class, 'handle'])
    ->name('plugin.epay_alipay.notify')
    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);

Route::get('/plugin/epay_alipay/return', [NotifyController::class, 'returnHandle'])
    ->name('plugin.epay_alipay.return');
