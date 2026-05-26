<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\User\Models\Client;
use App\Services\NotificationCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_center_service_creates_and_marks_messages(): void
    {
        $client = $this->client();
        $service = app(NotificationCenterService::class);

        $notification = $service->create($client, 'system', '系统消息', '欢迎使用');

        $this->assertSame(1, $service->getUnreadCount($client));
        $service->markAsRead($notification);
        $this->assertSame(0, $service->getUnreadCount($client));
        $this->assertTrue($notification->fresh()->read);
    }

    public function test_client_can_view_and_mark_notification_read(): void
    {
        $client = $this->client();
        $notification = app(NotificationCenterService::class)->create($client, 'system', '系统消息', '欢迎使用');

        $this->actingAs($client, 'client')
            ->get(route('client.notifications.index'))
            ->assertOk()
            ->assertSee('系统消息');

        $this->actingAs($client, 'client')
            ->post(route('client.notifications.read', $notification))
            ->assertRedirect();

        $this->assertTrue($notification->fresh()->read);
    }

    public function test_unread_count_endpoint_is_scoped_to_current_client(): void
    {
        $client = $this->client();
        $other = $this->client();
        app(NotificationCenterService::class)->create($client, 'system', '系统消息', '欢迎使用');
        app(NotificationCenterService::class)->create($other, 'system', '其他消息', '不应计入');

        $this->actingAs($client, 'client')
            ->getJson(route('client.notifications.unread-count'))
            ->assertOk()
            ->assertJson(['unread_count' => 1]);
    }

    public function test_invoice_generation_creates_in_app_notification(): void
    {
        $client = $this->client();

        app(InvoiceService::class)->generate($client, [
            ['type' => 'product', 'description' => '测试产品', 'amount' => 100],
        ]);

        $this->assertDatabaseHas('notifications', [
            'client_id' => $client->id,
            'type' => 'invoice',
            'title' => '账单已生成',
            'read' => false,
        ]);
    }

    public function test_client_cannot_mark_other_client_notification_read(): void
    {
        $client = $this->client();
        $other = $this->client();
        $notification = app(NotificationCenterService::class)->create($other, 'system', '其他消息', '不应读取');

        $this->actingAs($client, 'client')
            ->post(route('client.notifications.read', $notification))
            ->assertNotFound();

        $this->assertFalse(Notification::query()->find($notification->id)->read);
    }

    private function client(): Client
    {
        return Client::query()->create([
            'username' => 'notification-client-' . random_int(1000, 9999),
            'email' => 'notification-client-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
        ]);
    }
}
