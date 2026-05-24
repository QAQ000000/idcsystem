<?php

namespace App\Http\Middleware;

use App\Services\AdminAuditService;
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
            app(AdminAuditService::class)->record($request, 'admin.status.denied', null, 'failed', [
                'admin_user_id' => $admin->id,
                'status' => $admin->status,
                'method' => $request->method(),
                'path' => $request->path(),
            ], '管理员账号已被停用。');

            Auth::guard('admin')->logout();

            return redirect()->route('admin.login')->withErrors([
                'username' => '管理员账号已被停用。',
            ]);
        }

        return $next($request);
    }
}
