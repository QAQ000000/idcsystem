<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PerformanceMonitor
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);

        /** @var Response $response */
        $response = $next($request);

        $duration = round((microtime(true) - $startedAt) * 1000, 2);
        $response->headers->set('X-Response-Time', $duration . 'ms');

        if ((bool) config('performance.log_slow_requests', true)
            && $duration > (int) config('performance.slow_request_ms', 200)) {
            Log::warning('Slow request detected', [
                'method' => $request->method(),
                'path' => $request->path(),
                'route' => $request->route()?->getName(),
                'duration_ms' => $duration,
            ]);
        }

        return $response;
    }
}
