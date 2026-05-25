<?php

use Illuminate\Support\Facades\Route;
use Plugins\Gateway\EpayWxpay\src\NotifyController;

Route::match(['get', 'post'], '/plugin/epay_wxpay/notify', [NotifyController::class, 'handle'])
    ->name('plugin.epay_wxpay.notify')
    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);

Route::get('/plugin/epay_wxpay/return', [NotifyController::class, 'returnHandle'])
    ->name('plugin.epay_wxpay.return');
