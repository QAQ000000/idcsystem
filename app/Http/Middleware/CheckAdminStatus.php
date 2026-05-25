<?php

namespace App\Http\Middleware;

use App\Modules\Admin\Models\AdminUser;
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

        // 每次请求重新读取管理员状态，避免后台停用账号后旧会话继续访问。
        $freshAdmin = AdminUser::query()->whereKey($admin->getAuthIdentifier())->first();

        if (!$freshAdmin || !$freshAdmin->isActive()) {
            app(AdminAuditService::class)->record($request, 'admin.status.denied', null, 'failed', [
                'admin_user_id' => $admin->id,
                'status' => $freshAdmin?->status,
                'method' => $request->method(),
                'path' => $request->path(),
            ], '管理员账号已被停用。');

            Auth::guard('admin')->logout();

            return redirect()->route('admin.login')->withErrors([
                'username' => '管理员账号已被停用。',
            ]);
        }

        Auth::guard('admin')->setUser($freshAdmin);

        return $next($request);
    }
}
