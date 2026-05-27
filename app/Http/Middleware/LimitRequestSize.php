<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LimitRequestSize
{
    private const DEFAULT_MAX_BYTES = 1048576;

    public function handle(Request $request, Closure $next): Response
    {
        $maxBytes = (int) config('sanctum.max_request_body_bytes', self::DEFAULT_MAX_BYTES);
        $contentLength = $request->headers->get('Content-Length');

        if ($contentLength !== null && (int) $contentLength > $maxBytes) {
            return $this->tooLarge($maxBytes);
        }

        if ($contentLength === null && strlen($request->getContent()) > $maxBytes) {
            return $this->tooLarge($maxBytes);
        }

        return $next($request);
    }

    private function tooLarge(int $maxBytes): Response
    {
        return response()->json([
            'success' => false,
            'message' => '请求体过大。',
            'max_bytes' => $maxBytes,
        ], 413);
    }
}
