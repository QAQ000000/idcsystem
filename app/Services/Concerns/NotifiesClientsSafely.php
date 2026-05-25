<?php

namespace App\Services\Concerns;

use App\Modules\User\Models\Client;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;
use Throwable;

trait NotifiesClientsSafely
{
    private function notifyClientSafely(Client $client, string $event, array $variables, string $workflow): void
    {
        try {
            app(NotificationService::class)->notifyClient($client, $event, $variables);
        } catch (Throwable $exception) {
            Log::warning('Client notification failed without blocking workflow.', [
                'workflow' => $workflow,
                'event' => $event,
                'client_id' => $client->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
