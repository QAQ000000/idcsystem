<?php

use Illuminate\Support\Facades\Route;
use Plugins\Gateway\EpayBank\src\NotifyController;

Route::match(['get', 'post'], '/plugin/epay_bank/notify', [NotifyController::class, 'handle'])
    ->name('plugin.epay_bank.notify')
    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);

Route::get('/plugin/epay_bank/return', [NotifyController::class, 'returnHandle'])
    ->name('plugin.epay_bank.return');
