<?php

namespace App\Http\Middleware;

use App\Services\AdminAuditService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $admin = Auth::guard('admin')->user();

        if (!$admin) {
            return redirect()->route('admin.login');
        }

        if ($admin->hasRole('super-admin') || $admin->can($permission)) {
            return $next($request);
        }

        app(AdminAuditService::class)->record($request, 'admin.permission.denied', null, 'failed', [
            'permission' => $permission,
            'method' => $request->method(),
            'path' => $request->path(),
        ], '当前管理员没有执行该操作的权限。');

        abort(403, '当前管理员没有执行该操作的权限。');
    }
}
