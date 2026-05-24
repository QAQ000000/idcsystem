<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckAdminStatus
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('admin')->user();

        if (!$admin) {
            return redirect()->route('admin.login');
        }

        if (!$admin->isActive()) {
            Auth::guard('admin')->logout();

            return redirect()->route('admin.login')->withErrors([
                'username' => '管理员账号已被停用。',
            ]);
        }

        return $next($request);
    }
}
