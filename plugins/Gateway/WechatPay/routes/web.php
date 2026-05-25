<?php

use Illuminate\Support\Facades\Route;
use Plugins\Gateway\WechatPay\src\NotifyController;

Route::post('/plugin/wechat_pay/notify', [NotifyController::class, 'handle'])
    ->name('plugin.wechat_pay.notify')
    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);
