<?php

namespace App\Services;

use App\Models\AdminActionLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminAuditService
{
    public function record(
        Request $request,
        string $action,
        ?Model $target = null,
        string $result = 'success',
        array $payload = [],
        ?string $error = null
    ): AdminActionLog {
        return AdminActionLog::query()->create([
            'admin_user_id' => $request->user('admin')?->id,
            'action' => $action,
            'target_type' => $target ? $target->getMorphClass() : null,
            'target_id' => $target?->getKey(),
            'result' => $result,
            'payload' => $this->maskSensitive($payload),
            'error' => $error,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
        ]);
    }

    private function maskSensitive(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->maskSensitive($value);
                continue;
            }

            $normalized = strtolower((string) $key);
            if (str_contains($normalized, 'password')
                || str_contains($normalized, 'secret')
                || str_contains($normalized, 'token')
                || str_ends_with($normalized, 'key')) {
                $payload[$key] = '[FILTERED]';
            }
        }

        return $payload;
    }
}
