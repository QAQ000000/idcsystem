<?php

namespace Plugins\Captcha\ImageCaptcha\src;

use App\Plugins\Facades\Plugin;
use Illuminate\Http\Response;

class CaptchaController
{
    public function show(string $key): Response
    {
        $plugin = Plugin::type('captcha')->get('image_captcha');
        if (!$plugin instanceof ImageCaptchaPlugin) {
            abort(404);
        }

        return response($plugin->generateForKey($key), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }
}
