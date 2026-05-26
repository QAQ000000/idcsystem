<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Services\NotificationCenterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request, NotificationCenterService $notifications)
    {
        $client = $request->user('client');
        $items = Notification::query()
            ->where('client_id', $client->id)
            ->latest()
            ->paginate(20);
        $unreadCount = $notifications->getUnreadCount($client);

        return view('theme::notifications.index', compact('items', 'unreadCount'));
    }

    public function read(Request $request, Notification $notification, NotificationCenterService $notifications)
    {
        abort_unless((int) $notification->client_id === (int) $request->user('client')->id, 404);

        $notifications->markAsRead($notification);

        return back()->with('status', '消息已标记为已读');
    }

    public function readAll(Request $request, NotificationCenterService $notifications)
    {
        $count = $notifications->markAllAsRead($request->user('client'));

        return back()->with('status', "已标记 {$count} 条消息");
    }

    public function unreadCount(Request $request, NotificationCenterService $notifications): JsonResponse
    {
        return response()->json([
            'unread_count' => $notifications->getUnreadCount($request->user('client')),
        ]);
    }
}
