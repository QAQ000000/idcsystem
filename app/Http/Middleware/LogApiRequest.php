<?php

namespace App\Http\Middleware;

use App\Models\ClientActivityLog;
use Closure;
use Illuminate\Http\Request;

class LogApiRequest
{
    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);
        $client = $request->user();
        $token = $client?->currentAccessToken();

        if ($client && $token) {
            ClientActivityLog::query()->create([
                'client_id' => $client->id,
                'action' => 'api.request',
                'description' => "{$request->method()} {$request->path()}",
                'meta' => ['token_id' => $token->id],
                'ip' => $request->ip(),
                'created_at' => now(),
            ]);
        }

        return $response;
    }
}
