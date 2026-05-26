<?php

namespace Tests\Feature;

use App\Jobs\ExportClientDataJob;
use App\Models\DataDeletionRequest;
use App\Models\DataExportRequest;
use App\Models\PrivacyPolicyConsent;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Finance\Models\Currency;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Order\Models\Order;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketDepartment;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\User\Models\Client;
use App\Services\GdprService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GdprTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_requires_and_records_privacy_policy_consent(): void
    {
        config(['app.privacy_policy_version' => '2026-05']);

        $this->post(route('client.register.store'), [
            'username' => 'gdpr-register',
            'email' => 'gdpr-register@example.com',
            'password' => 'client123456',
            'password_confirmation' => 'client123456',
        ])->assertSessionHasErrors('privacy_policy');

        $this->post(route('client.register.store'), [
            'username' => 'gdpr-register',
            'email' => 'gdpr-register@example.com',
            'password' => 'client123456',
            'password_confirmation' => 'client123456',
            'privacy_policy' => '1',
        ])->assertRedirect(route('verification.notice'));

        $client = Client::query()->where('email', 'gdpr-register@example.com')->firstOrFail();
        $this->assertDatabaseHas('privacy_policy_consents', [
            'client_id' => $client->id,
            'policy_version' => '2026-05',
        ]);
    }

    public function test_client_can_request_export_and_download_completed_json(): void
    {
        Queue::fake();
        Storage::fake('local');
        $client = $this->client();
        $this->makeClientData($client);

        $this->actingAs($client, 'client')
            ->post(route('client.account.export-data'))
            ->assertRedirect(route('client.account.privacy'))
            ->assertSessionHas('status', '数据导出请求已创建，请稍后下载。');

        $request = DataExportRequest::query()->where('client_id', $client->id)->firstOrFail();
        Queue::assertPushed(ExportClientDataJob::class, fn (ExportClientDataJob $job) => $job->requestId === $request->id);

        app(GdprService::class)->exportData($request);
        $request->refresh();
        Storage::disk('local')->assertExists($request->file_path);

        $response = $this->actingAs($client, 'client')
            ->get(route('client.account.export-data.download', $request))
            ->assertOk();

        $this->assertStringContainsString('gdpr-ticket', $response->streamedContent());
    }

    public function test_export_download_cannot_be_accessed_by_other_client(): void
    {
        Storage::fake('local');
        $owner = $this->client();
        $other = $this->client();
        $request = DataExportRequest::query()->create([
            'client_id' => $owner->id,
            'status' => 'completed',
            'file_path' => 'gdpr/exports/test.json',
            'completed_at' => now(),
        ]);
        Storage::disk('local')->put('gdpr/exports/test.json', '{}');

        $this->actingAs($other, 'client')
            ->get(route('client.account.export-data.download', $request))
            ->assertForbidden();
    }

    public function test_client_delete_request_can_be_approved_and_anonymizes_account(): void
    {
        $admin = $this->admin();
        $client = $this->client();
        $this->actingAs($client, 'client')
            ->post(route('client.account.delete-account'), ['reason' => 'privacy'])
            ->assertRedirect(route('client.account.privacy'));

        $request = DataDeletionRequest::query()->where('client_id', $client->id)->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.gdpr.deletion-requests.approve', $request), ['admin_notes' => 'ok'])
            ->assertRedirect(route('admin.gdpr.deletion-requests.index'))
            ->assertSessionHas('status', '删除请求已批准并完成匿名化。');

        $client->refresh();
        $this->assertSame(2, $client->status);
        $this->assertSame('deleted-client-' . $client->id, $client->username);
        $this->assertSame('deleted-client-' . $client->id . '@privacy.local', $client->email);
        $this->assertSame('completed', $request->fresh()->status);
        $this->assertDatabaseHas('admin_action_logs', [
            'action' => 'gdpr.deletion.approve',
            'result' => 'success',
        ]);
    }

    public function test_admin_can_reject_pending_delete_request(): void
    {
        $admin = $this->admin();
        $request = DataDeletionRequest::query()->create([
            'client_id' => $this->client()->id,
            'reason' => 'not now',
            'status' => 'pending',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.gdpr.deletion-requests.reject', $request), ['admin_notes' => 'need review'])
            ->assertRedirect(route('admin.gdpr.deletion-requests.index'))
            ->assertSessionHas('status', '删除请求已拒绝。');

        $this->assertSame('rejected', $request->fresh()->status);
        $this->assertDatabaseHas('admin_action_logs', [
            'action' => 'gdpr.deletion.reject',
            'result' => 'success',
        ]);
    }

    private function makeClientData(Client $client): void
    {
        Order::query()->create([
            'client_id' => $client->id,
            'order_number' => 'ORD-GDPR-1',
            'status' => 'Paid',
            'amount' => 100,
            'currency_id' => $client->currency_id,
        ]);
        Invoice::query()->create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-GDPR-1',
            'subtotal' => 100,
            'tax' => 0,
            'tax_rate' => 0,
            'credit_used' => 0,
            'total' => 100,
            'status' => 'Paid',
            'due_date' => now(),
        ]);
        $department = TicketDepartment::query()->create(['name' => 'GDPR']);
        $status = TicketStatus::query()->create(['name' => 'Open', 'color' => '#000000', 'is_closed' => false, 'sort_order' => 1]);
        Ticket::query()->create([
            'ticket_number' => 'TIC-GDPR-1',
            'client_id' => $client->id,
            'department_id' => $department->id,
            'status_id' => $status->id,
            'subject' => 'gdpr-ticket',
            'message' => 'export me',
            'priority' => 'medium',
        ]);
        PrivacyPolicyConsent::query()->create([
            'client_id' => $client->id,
            'policy_version' => '1.0',
            'ip' => '127.0.0.1',
            'consented_at' => now(),
        ]);
    }

    private function client(): Client
    {
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );

        return Client::query()->create([
            'username' => 'gdpr-client-' . random_int(1000, 9999),
            'email' => 'gdpr-client-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => $currency->id,
        ]);
    }

    private function admin(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'gdpr-admin-' . random_int(1000, 9999),
            'email' => 'gdpr-admin-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);

        $role = Role::query()->firstOrCreate(['name' => 'gdpr-admin', 'guard_name' => 'web']);
        $admin->syncRoles([$role]);
        $admin->givePermissionTo(Permission::query()->firstOrCreate(['name' => 'gdpr.manage', 'guard_name' => 'web']));

        return $admin;
    }
}
