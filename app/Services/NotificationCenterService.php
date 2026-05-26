<?php

namespace App\Services;

use App\Models\Notification;
use App\Modules\User\Models\Client;
use Illuminate\Support\Collection;

class NotificationCenterService
{
    public function create(Client $client, string $type, string $title, string $content, array $data = []): Notification
    {
        return Notification::query()->create([
            'client_id' => $client->id,
            'type' => $type,
            'title' => $title,
            'content' => $content,
            'data' => $data === [] ? null : $data,
            'read' => false,
        ]);
    }

    public function markAsRead(Notification $notification): void
    {
        if ($notification->read) {
            return;
        }

        $notification->forceFill([
            'read' => true,
            'read_at' => now(),
        ])->save();
    }

    public function markAllAsRead(Client $client): int
    {
        return Notification::query()
            ->where('client_id', $client->id)
            ->where('read', false)
            ->update([
                'read' => true,
                'read_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function getUnreadCount(Client $client): int
    {
        return Notification::query()
            ->where('client_id', $client->id)
            ->where('read', false)
            ->count();
    }

    public function getNotifications(Client $client, int $limit = 20): Collection
    {
        return Notification::query()
            ->where('client_id', $client->id)
            ->latest()
            ->limit(max(1, min(100, $limit)))
            ->get();
    }
}
