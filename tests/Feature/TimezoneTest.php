<?php

namespace Tests\Feature;

use App\Models\ClientActivityLog;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Finance\Models\Currency;
use App\Modules\User\Models\Client;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_set_timezone_from_profile(): void
    {
        $client = $this->client(['timezone' => 'Asia/Shanghai']);

        $this->actingAs($client, 'client')
            ->get(route('client.account.profile'))
            ->assertOk()
            ->assertSee('Time Zone');

        $this->actingAs($client, 'client')
            ->put(route('client.account.profile.update'), [
                'currency_id' => $client->currency_id,
                'locale' => 'en',
                'timezone' => 'America/New_York',
            ])
            ->assertRedirect(route('client.account.profile'));

        $this->assertSame('America/New_York', $client->fresh()->timezone);
        $this->assertSame('UTC', config('app.timezone'));
    }

    public function test_client_activity_times_render_in_client_timezone_without_changing_app_timezone(): void
    {
        $client = $this->client(['timezone' => 'Asia/Tokyo']);
        ClientActivityLog::query()->create([
            'client_id' => $client->id,
            'action' => 'timezone.test',
            'description' => 'Timezone test',
            'meta' => [],
            'ip' => '127.0.0.1',
            'created_at' => Carbon::parse('2026-05-27 00:00:00', 'UTC'),
        ]);

        $this->actingAs($client, 'client')
            ->get(route('client.account.activity'))
            ->assertOk()
            ->assertSee('2026-05-27 09:00:00');

        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('2026-05-27 00:00:00', ClientActivityLog::query()->firstOrFail()->created_at->timezone('UTC')->format('Y-m-d H:i:s'));
    }

    public function test_timezone_helpers_convert_user_input_to_utc(): void
    {
        config(['app.user_timezone' => 'Asia/Tokyo']);

        $this->assertSame('2026-05-27 09:00:00', userTime(Carbon::parse('2026-05-27 00:00:00', 'UTC')));
        $this->assertSame('2026-05-27 00:00:00', toUtc('2026-05-27 09:00:00')->format('Y-m-d H:i:s'));
    }

    public function test_admin_timezone_is_available_for_admin_requests(): void
    {
        $admin = AdminUser::query()->create([
            'username' => 'timezone-admin',
            'email' => 'timezone-admin@example.com',
            'password' => Hash::make('admin123456'),
            'timezone' => 'Europe/London',
            'status' => 1,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk();

        $this->assertSame('Europe/London', config('app.user_timezone'));
        $this->assertSame('UTC', config('app.timezone'));
    }

    private function client(array $overrides = []): Client
    {
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );

        return Client::query()->create(array_merge([
            'username' => 'timezone-client-' . random_int(1000, 9999),
            'email' => 'timezone-client-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => $currency->id,
            'locale' => 'en',
            'timezone' => 'Asia/Shanghai',
        ], $overrides));
    }
}
