<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckClientStatus
{
    public function handle(Request $request, Closure $next): Response
    {
        $client = Auth::guard('client')->user();

        if (!$client) {
            return redirect()->route('client.login');
        }

        if (!$client->isActive()) {
            Auth::guard('client')->logout();

            return redirect()->route('client.login')->withErrors([
                'email' => '客户账号未启用或已被关闭。',
            ]);
        }

        return $next($request);
    }
}
