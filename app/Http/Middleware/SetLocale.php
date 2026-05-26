<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetLocale
{
    public function handle(Request $request, Closure $next): mixed
    {
        $locale = $request->query('lang')
            ?? $request->session()->get('locale')
            ?? auth('client')->user()?->locale
            ?? config('app.locale');

        if (in_array($locale, config('app.available_locales', ['zh_CN', 'en']), true)) {
            app()->setLocale($locale);
            $request->session()->put('locale', $locale);
        }

        return $next($request);
    }
}
