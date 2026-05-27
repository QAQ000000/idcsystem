<?php

namespace Tests\Feature;

use App\Modules\Finance\Services\BillingService;
use App\Modules\Order\Models\Host;
use App\Modules\Order\Models\Order;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductGroup;
use App\Modules\Support\Models\MarketingAutomation;
use App\Modules\Support\Models\MarketingAutomationExecution;
use App\Modules\Support\Services\MarketingAutomationService;
use App\Modules\User\Models\Client;
use App\Modules\User\Models\ClientSegment;
use App\Modules\User\Models\ClientTag;
use App\Modules\User\Services\AuthService;
use App\Services\HostMonitoringService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MarketingAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_registered_event_starts_welcome_sequence(): void
    {
        $tag = $this->tag('welcome');
        $this->automation('client.registered', [
            ['action' => 'add_tag', 'tag' => $tag->slug],
        ]);

        $client = app(AuthService::class)->register([
            'username' => 'welcome-client',
            'email' => 'welcome-client@example.com',
            'password' => 'client123456',
            'privacy_ip' => '127.0.0.1',
        ]);

        $this->assertDatabaseHas('marketing_automation_executions', [
            'client_id' => $client->id,
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('client_tag_pivot', [
            'client_id' => $client->id,
            'client_tag_id' => $tag->id,
        ]);
        $this->assertDatabaseHas('marketing_automation_logs', [
            'action' => 'add_tag',
            'status' => 'success',
        ]);
    }

    public function test_trigger_conditions_skip_non_matching_events(): void
    {
        $client = $this->client('condition-client');
        $tag = $this->tag('vip');
        $this->automation('client.registered', [
            ['action' => 'add_tag', 'tag' => $tag->slug],
        ], [
            ['field' => 'plan', 'operator' => '=', 'value' => 'enterprise'],
        ]);

        $started = app(MarketingAutomationService::class)->trigger('client.registered', [
            'client_id' => $client->id,
            'plan' => 'starter',
        ]);

        $this->assertSame(0, $started);
        $this->assertDatabaseCount('marketing_automation_executions', 0);
        $this->assertDatabaseMissing('client_tag_pivot', [
            'client_id' => $client->id,
            'client_tag_id' => $tag->id,
        ]);
    }

    public function test_wait_step_delays_and_due_command_resumes_next_step(): void
    {
        Queue::fake();
        $client = $this->client('wait-client');
        $tag = $this->tag('after-wait');
        $this->automation('client.registered', [
            ['action' => 'wait', 'minutes' => 30],
            ['action' => 'add_tag', 'tag' => $tag->slug],
        ]);

        app(MarketingAutomationService::class)->trigger('client.registered', ['client_id' => $client->id]);
        $execution = MarketingAutomationExecution::query()->firstOrFail();

        $this->assertSame('running', $execution->status);
        $this->assertSame(1, $execution->current_step);
        $this->assertNotNull($execution->next_run_at);
        $this->assertDatabaseMissing('client_tag_pivot', [
            'client_id' => $client->id,
            'client_tag_id' => $tag->id,
        ]);

        $execution->update(['next_run_at' => now()->subMinute()]);
        $this->artisan('marketing-automations:process-due')->assertExitCode(0);

        $this->assertSame('completed', $execution->fresh()->status);
        $this->assertDatabaseHas('client_tag_pivot', [
            'client_id' => $client->id,
            'client_tag_id' => $tag->id,
        ]);
    }

    public function test_invoice_overdue_trigger_runs_after_host_suspension(): void
    {
        $client = $this->client('overdue-client');
        $tag = $this->tag('overdue');
        $this->automation('invoice.overdue', [
            ['action' => 'add_tag', 'tag' => $tag->slug],
        ]);
        $host = $this->host($client, [
            'status' => 'Active',
            'next_due_date' => now()->subDays(3),
        ]);

        $this->assertSame(1, app(BillingService::class)->suspendOverdueHosts());

        $this->assertSame('Suspended', $host->fresh()->status);
        $this->assertDatabaseHas('client_tag_pivot', [
            'client_id' => $client->id,
            'client_tag_id' => $tag->id,
        ]);
    }

    public function test_host_expiring_trigger_runs_after_due_reminder(): void
    {
        $client = $this->client('expiring-client');
        $tag = $this->tag('renewal-reminded');
        $this->automation('host.expiring', [
            ['action' => 'add_tag', 'tag' => $tag->slug],
        ]);
        $this->host($client, [
            'status' => 'Active',
            'next_due_date' => now()->addDays(3),
        ]);
        $this->instance(NotificationService::class, new class extends NotificationService {
            public function notifyClient(\App\Modules\User\Models\Client $client, string $event, array $variables = []): array
            {
                return ['mail' => true, 'sms' => null, 'errors' => []];
            }
        });

        $result = app(HostMonitoringService::class)->sendDueReminders(7);

        $this->assertSame(1, $result['notified']);
        $this->assertDatabaseHas('client_tag_pivot', [
            'client_id' => $client->id,
            'client_tag_id' => $tag->id,
        ]);
    }

    public function test_ab_variant_and_segment_action_are_recorded(): void
    {
        $client = $this->client('variant-client');
        $segment = ClientSegment::query()->create([
            'name' => '自动化分群',
            'type' => 'static',
        ]);
        $left = $this->tag('variant-left');
        $right = $this->tag('variant-right');
        $this->automation('client.registered', [
            [
                'action' => 'add_tag',
                'variants' => [
                    ['tag' => $left->slug],
                    ['tag' => $right->slug],
                ],
            ],
            ['action' => 'add_to_segment', 'segment_id' => $segment->id],
        ]);

        app(MarketingAutomationService::class)->trigger('client.registered', ['client_id' => $client->id]);

        $expectedTag = $client->id % 2 === 0 ? $left : $right;
        $this->assertDatabaseHas('client_tag_pivot', [
            'client_id' => $client->id,
            'client_tag_id' => $expectedTag->id,
        ]);
        $this->assertDatabaseHas('client_segment_members', [
            'segment_id' => $segment->id,
            'client_id' => $client->id,
        ]);
        $this->assertSame(1, $segment->fresh()->clients_count);
        $this->assertDatabaseHas('marketing_automation_logs', [
            'action' => 'add_to_segment',
            'status' => 'success',
        ]);
    }

    private function automation(string $event, array $steps, array $conditions = []): MarketingAutomation
    {
        return MarketingAutomation::query()->create([
            'name' => 'Automation ' . random_int(1000, 9999),
            'trigger_event' => $event,
            'trigger_conditions' => $conditions,
            'steps' => $steps,
            'is_active' => true,
        ]);
    }

    private function tag(string $slug): ClientTag
    {
        return ClientTag::query()->create([
            'name' => str_replace('-', ' ', $slug),
            'slug' => $slug . '-' . random_int(1000, 9999),
            'color' => '#3B82F6',
        ]);
    }

    private function client(string $username): Client
    {
        return Client::query()->create([
            'username' => $username . '-' . random_int(1000, 9999),
            'email' => $username . '-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
        ]);
    }

    private function host(Client $client, array $overrides = []): Host
    {
        $group = ProductGroup::query()->firstOrCreate(['name' => '自动化产品']);
        $product = Product::query()->create([
            'group_id' => $group->id,
            'name' => 'Automation VPS ' . random_int(1000, 9999),
            'type' => 'hosting',
            'hidden' => false,
            'retired' => false,
            'stock_control' => false,
        ]);
        $order = Order::query()->create([
            'client_id' => $client->id,
            'order_number' => 'ORD-AUTO-' . random_int(1000, 9999),
            'status' => 'Paid',
            'amount' => 50,
        ]);

        return Host::query()->create(array_merge([
            'client_id' => $client->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'domain' => 'auto-' . random_int(1000, 9999) . '.example.com',
            'billing_cycle' => 'monthly',
            'first_payment_amount' => 50,
            'recurring_amount' => 50,
            'status' => 'Active',
            'auto_renew' => true,
        ], $overrides));
    }
}
