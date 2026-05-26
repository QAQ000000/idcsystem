<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckApiAbility
{
    public function handle(Request $request, Closure $next, string $ability): mixed
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['success' => false, 'message' => '权限不足'], 403);
        }

        if (!$user->currentAccessToken()) {
            return $next($request);
        }

        if (!$user->tokenCan($ability)) {
            return response()->json(['success' => false, 'message' => '权限不足'], 403);
        }

        return $next($request);
    }
}
