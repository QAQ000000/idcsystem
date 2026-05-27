<?php

namespace Tests\Feature;

use App\Jobs\SendCampaignEmailJob;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailLog;
use App\Models\Plugin;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Finance\Models\Currency;
use App\Modules\User\Models\Client;
use App\Modules\User\Models\ClientGroup;
use App\Modules\User\Models\ClientSegment;
use App\Plugins\Core\PluginManager;
use App\Services\EmailCampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmailCampaignTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_create_calculates_group_recipients(): void
    {
        $group = ClientGroup::query()->create(['name' => 'VIP']);
        $client = $this->client('vip-client', 'vip@example.com', $group);
        $this->client('normal-client', 'normal@example.com');

        $campaign = app(EmailCampaignService::class)->create([
            'name' => 'VIP 活动',
            'subject' => '专属优惠',
            'content' => '<p>您好 {{client_name}}</p>',
            'target_groups' => [$group->id],
        ]);

        $this->assertSame(1, $campaign->total_recipients);
        $this->assertDatabaseHas('email_campaign_recipients', [
            'campaign_id' => $campaign->id,
            'client_id' => $client->id,
            'status' => 'pending',
        ]);
    }

    public function test_campaign_create_calculates_segment_recipients(): void
    {
        $client = $this->client('segment-client', 'segment@example.com');
        $this->client('outside-segment-client', 'outside-segment@example.com');
        $segment = ClientSegment::query()->create([
            'name' => '活动分群',
            'type' => 'static',
            'clients_count' => 1,
        ]);
        $segment->clients()->attach($client->id, ['added_at' => now()]);

        $campaign = app(EmailCampaignService::class)->create([
            'name' => '分群活动',
            'subject' => '分群优惠',
            'content' => '<p>您好 {{client_name}}</p>',
            'target_segments' => [$segment->id],
        ]);

        $this->assertSame(1, $campaign->total_recipients);
        $this->assertSame([$segment->id], $campaign->target_segments);
        $this->assertDatabaseHas('email_campaign_recipients', [
            'campaign_id' => $campaign->id,
            'client_id' => $client->id,
            'status' => 'pending',
        ]);
    }

    public function test_send_dispatches_jobs_and_job_marks_recipient_sent(): void
    {
        $this->installSmtp();
        Queue::fake();
        $client = $this->client('send-client', 'send@example.com');
        $campaign = app(EmailCampaignService::class)->create([
            'name' => '发送活动',
            'subject' => '活动主题',
            'content' => '<p>您好 {{client_name}}</p><a href="https://example.com/deal">查看</a>',
            'target_groups' => [],
        ]);

        app(EmailCampaignService::class)->send($campaign);

        Queue::assertPushed(SendCampaignEmailJob::class);
        $this->assertSame('sending', $campaign->fresh()->status);

        $recipient = EmailCampaignRecipient::query()->where('client_id', $client->id)->firstOrFail();
        app(SendCampaignEmailJob::class, ['recipientId' => $recipient->id])->handle(app(\App\Services\MailService::class), app(EmailCampaignService::class));

        $recipient->refresh();
        $campaign->refresh();
        $this->assertSame('sent', $recipient->status);
        $this->assertSame(1, $campaign->sent_count);
        $this->assertSame('sent', $campaign->status);
        $this->assertNotNull($campaign->sent_at);

        $log = EmailLog::query()->where('to', 'send@example.com')->firstOrFail();
        $this->assertStringContainsString('/campaign/track/open/' . $recipient->id, (string) $log->body);
        $this->assertStringContainsString('/campaign/track/click/' . $recipient->id, (string) $log->body);
    }

    public function test_open_and_click_tracking_are_idempotent(): void
    {
        $client = $this->client();
        $campaign = app(EmailCampaignService::class)->create([
            'name' => '追踪活动',
            'subject' => '追踪',
            'content' => '<p>内容</p><a href="https://example.com">查看</a>',
            'target_groups' => [],
        ]);
        $recipient = EmailCampaignRecipient::query()->where('campaign_id', $campaign->id)->where('client_id', $client->id)->firstOrFail();

        $rendered = app(EmailCampaignService::class)->renderForRecipient($recipient);
        preg_match('/src="([^"]*\/campaign\/track\/open\/[^"]+)"/', $rendered, $openMatches);
        preg_match('/href="([^"]*\/campaign\/track\/click\/[^"]+)"/', $rendered, $clickMatches);

        $this->assertNotEmpty($openMatches[1] ?? null);
        $this->assertNotEmpty($clickMatches[1] ?? null);

        $this->get(html_entity_decode($openMatches[1]))->assertOk();
        $this->get(html_entity_decode($openMatches[1]))->assertOk();
        $this->get(html_entity_decode($clickMatches[1]))
            ->assertRedirect('https://example.com');
        $this->get(html_entity_decode($clickMatches[1]))
            ->assertRedirect('https://example.com');

        $campaign->refresh();
        $this->assertSame(1, $campaign->opened_count);
        $this->assertSame(1, $campaign->clicked_count);
    }

    public function test_admin_can_create_schedule_and_command_dispatches_scheduled_campaign(): void
    {
        Queue::fake();
        $group = ClientGroup::query()->create(['name' => '公告客户']);
        $this->client('campaign-admin-client', 'campaign-admin@example.com', $group);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.campaigns.store'), [
                'name' => '公告活动',
                'subject' => '公告主题',
                'content' => '<p>公告内容</p>',
                'target_groups' => [$group->id],
                'target_segments' => [],
            ])
            ->assertRedirect();

        $campaign = EmailCampaign::query()->where('name', '公告活动')->firstOrFail();
        $this->assertSame(1, $campaign->total_recipients);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.campaigns.schedule', $campaign), [
                'scheduled_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect(route('admin.campaigns.show', $campaign))
            ->assertSessionHas('status', '邮件活动已安排发送');

        $this->artisan('campaigns:send-scheduled')->assertExitCode(0);

        $this->assertSame('sending', $campaign->fresh()->status);
        Queue::assertPushed(SendCampaignEmailJob::class);
    }

    public function test_campaign_with_no_recipients_finishes_without_jobs(): void
    {
        Queue::fake();
        $group = ClientGroup::query()->create(['name' => '空分组']);
        $campaign = app(EmailCampaignService::class)->create([
            'name' => '空活动',
            'subject' => '没有收件人',
            'content' => '<p>内容</p>',
            'target_groups' => [$group->id],
        ]);

        $this->assertSame(0, $campaign->total_recipients);

        app(EmailCampaignService::class)->send($campaign);

        $campaign->refresh();
        $this->assertSame('sent', $campaign->status);
        $this->assertNotNull($campaign->sent_at);
        Queue::assertNotPushed(SendCampaignEmailJob::class);
    }

    private function client(string $username = 'campaign-client', string $email = 'campaign@example.com', ?ClientGroup $group = null): Client
    {
        $group ??= ClientGroup::query()->firstOrCreate(['name' => '默认客户组']);
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );

        return Client::query()->create([
            'username' => $username,
            'email' => $email,
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => $currency->id,
            'group_id' => $group?->id,
        ]);
    }

    private function admin(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'campaign-admin',
            'email' => 'campaign-admin@example.com',
            'password' => Hash::make('password'),
            'status' => 1,
        ]);

        Role::query()->firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['super-admin']);

        return $admin;
    }

    private function installSmtp(): void
    {
        $manager = app(PluginManager::class);
        $manager->install('email', 'smtp');
        $manager->enable('smtp');
        Plugin::query()->where('name', 'smtp')->update([
            'config' => ['host' => 'smtp.example.com', 'port' => 587],
        ]);
    }
}
