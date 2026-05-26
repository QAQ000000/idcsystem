<?php

namespace App\Services;

use App\Jobs\DeliverWebhookJob;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Http;
use Throwable;

class WebhookService
{
    public function dispatch(string $event, array $payload): void
    {
        Webhook::query()
            ->where('active', true)
            ->orderBy('id')
            ->get()
            ->filter(fn (Webhook $webhook): bool => in_array($event, $webhook->events ?? [], true))
            ->each(function (Webhook $webhook) use ($event, $payload): void {
                $delivery = $webhook->deliveries()->create([
                    'event' => $event,
                    'payload' => $payload,
                    'status' => 'pending',
                ]);

                DeliverWebhookJob::dispatch($delivery->id)->onQueue('default');
            });
    }

    public function deliver(WebhookDelivery $delivery): bool
    {
        $delivery->loadMissing('webhook');
        $webhook = $delivery->webhook;
        if (!$webhook || !$webhook->active) {
            $delivery->update([
                'status' => 'failed',
                'response' => 'Webhook inactive or missing.',
                'delivered_at' => now(),
            ]);

            return false;
        }

        $payload = json_encode($delivery->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = 'sha256=' . hash_hmac('sha256', (string) $payload, (string) $webhook->secret);

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Webhook-Event' => $delivery->event,
                    'X-Webhook-Signature' => $signature,
                ])
                ->send('POST', $webhook->url, ['body' => $payload]);

            $success = $response->successful();
            $delivery->update([
                'status_code' => $response->status(),
                'response' => substr($response->body(), 0, 5000),
                'status' => $success ? 'success' : 'failed',
                'delivered_at' => now(),
            ]);

            return $success;
        } catch (Throwable $exception) {
            $delivery->update([
                'status' => 'failed',
                'response' => substr($exception->getMessage(), 0, 5000),
                'delivered_at' => now(),
            ]);

            return false;
        }
    }

    public function test(Webhook $webhook): WebhookDelivery
    {
        return $webhook->deliveries()->create([
            'event' => 'webhook.test',
            'payload' => [
                'event' => 'webhook.test',
                'webhook_id' => $webhook->id,
                'sent_at' => now()->toIso8601String(),
            ],
            'status' => 'pending',
        ]);
    }
}
