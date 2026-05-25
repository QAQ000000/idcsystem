<?php

use Illuminate\Support\Facades\Route;
use Plugins\Oauth\WechatOAuth\src\CallbackController;

Route::get('/plugin/oauth/wechat', [CallbackController::class, 'redirect'])
    ->middleware('throttle:10,1')
    ->name('plugin.oauth.wechat.redirect');

Route::get('/plugin/oauth/wechat/callback', [CallbackController::class, 'callback'])
    ->middleware('throttle:10,1')
    ->name('plugin.oauth.wechat.callback');
