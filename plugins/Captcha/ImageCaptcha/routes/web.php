<?php

use Illuminate\Support\Facades\Route;
use Plugins\Captcha\ImageCaptcha\src\CaptchaController;

Route::get('/plugin/captcha/image/{key}', [CaptchaController::class, 'show'])
    ->where('key', '[A-Za-z0-9]{32,80}')
    ->middleware('throttle:60,1')
    ->name('plugin.captcha.image');
