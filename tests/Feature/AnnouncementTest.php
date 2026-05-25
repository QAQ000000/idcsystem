<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Finance\Models\Currency;
use App\Modules\User\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_manage_announcements_and_toggle_status(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.announcements.store'), [
                'title' => '计划维护',
                'content' => '今晚 23:00 维护',
                'type' => 'maintenance',
                'active' => '1',
                'starts_at' => now()->subHour()->format('Y-m-d H:i:s'),
                'ends_at' => now()->addDay()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect(route('admin.announcements.index'))
            ->assertSessionHas('status', '公告已创建');

        $announcement = Announcement::query()->where('title', '计划维护')->firstOrFail();
        $this->assertTrue($announcement->active);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.announcements.update', $announcement), [
                'title' => '维护完成',
                'content' => '维护已完成',
                'type' => 'info',
                'active' => '1',
            ])
            ->assertRedirect(route('admin.announcements.index'))
            ->assertSessionHas('status', '公告已保存');

        $this->assertSame('维护完成', $announcement->fresh()->title);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.announcements.toggle', $announcement))
            ->assertRedirect(route('admin.announcements.index'))
            ->assertSessionHas('status', '公告已停用');

        $this->assertFalse($announcement->fresh()->active);
    }

    public function test_client_dashboard_shows_only_visible_announcements(): void
    {
        $client = $this->client();
        Announcement::query()->create([
            'title' => '可见公告',
            'content' => '客户可以看到',
            'type' => 'info',
            'active' => true,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
        ]);
        Announcement::query()->create([
            'title' => '过期公告',
            'content' => '客户不应看到',
            'type' => 'warning',
            'active' => true,
            'ends_at' => now()->subMinute(),
        ]);

        $this->actingAs($client, 'client')
            ->get(route('client.dashboard'))
            ->assertOk()
            ->assertSee('可见公告')
            ->assertSee('客户可以看到')
            ->assertDontSee('过期公告');
    }

    private function admin(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'announcement-admin',
            'email' => 'announcement-admin@example.com',
            'password' => Hash::make('password'),
            'status' => 1,
        ]);

        Role::query()->firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['super-admin']);

        return $admin;
    }

    private function client(): Client
    {
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );

        return Client::query()->create([
            'username' => 'announcement-client',
            'email' => 'announcement-client@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => $currency->id,
        ]);
    }
}
