<?php

namespace App\Http\Middleware;

use Closure;
use DateTimeZone;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetTimezone
{
    public function handle(Request $request, Closure $next): Response
    {
        $timezone = auth('client')->user()?->timezone
            ?? auth('admin')->user()?->timezone
            ?? config('app.timezone', 'UTC');

        if (in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            config(['app.user_timezone' => $timezone]);
        }

        return $next($request);
    }
}
