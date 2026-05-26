<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\WebhookService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public int $deliveryId)
    {
        $this->onQueue('default');
    }

    public function handle(WebhookService $webhookService): void
    {
        $delivery = WebhookDelivery::query()->find($this->deliveryId);
        if ($delivery) {
            $webhookService->deliver($delivery);
        }
    }
}
