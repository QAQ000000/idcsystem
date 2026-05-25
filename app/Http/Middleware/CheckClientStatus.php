<?php

namespace App\Http\Middleware;

use App\Modules\User\Models\Client;
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

        // 每次请求重新读取客户状态，避免后台禁用账号后旧会话继续操作。
        $freshClient = Client::query()->whereKey($client->getAuthIdentifier())->first();

        if (!$freshClient || !$freshClient->isActive()) {
            Auth::guard('client')->logout();

            return redirect()->route('client.login')->withErrors([
                'email' => '客户账号未启用或已被关闭。',
            ]);
        }

        Auth::guard('client')->setUser($freshClient);

        return $next($request);
    }
}
