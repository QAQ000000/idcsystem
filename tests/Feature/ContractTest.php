<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Finance\Models\Contract;
use App\Modules\Finance\Models\ContractTemplate;
use App\Modules\Finance\Models\Currency;
use App\Modules\Finance\Services\ContractService;
use App\Modules\Order\Models\Order;
use App\Modules\User\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_manage_contract_templates(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.contract-templates.store'), [
                'name' => '云服务合同',
                'content' => '客户 {client_name} 订单 {order_number}',
                'active' => 1,
            ])
            ->assertRedirect(route('admin.contract-templates.index'))
            ->assertSessionHas('status', '合同模板已创建');

        $template = ContractTemplate::query()->where('name', '云服务合同')->firstOrFail();
        $this->assertTrue($template->active);
        $this->assertDatabaseHas('admin_action_logs', [
            'action' => 'contract_template.create',
            'target_id' => $template->id,
            'result' => 'success',
        ]);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.contract-templates.update', $template), [
                'name' => '云服务合同 v2',
                'content' => '更新后 {client_email}',
                'active' => 0,
            ])
            ->assertRedirect(route('admin.contract-templates.index'));

        $this->assertSame('云服务合同 v2', $template->fresh()->name);
        $this->assertFalse($template->fresh()->active);
    }

    public function test_contract_service_generates_contract_from_template_variables(): void
    {
        $client = $this->client();
        $order = $this->order($client);
        $template = ContractTemplate::query()->create([
            'name' => '标准合同',
            'content' => '客户：{client_name} 邮箱：{client_email} 订单：{order_number} 金额：{order_amount}',
            'active' => true,
        ]);

        $contract = app(ContractService::class)->generate($client, $order, $template);

        $this->assertSame($client->id, $contract->client_id);
        $this->assertSame($order->id, $contract->order_id);
        $this->assertSame('pending_signature', $contract->status);
        $this->assertStringContainsString($client->username, $contract->content);
        $this->assertStringContainsString($order->order_number, $contract->content);
    }

    public function test_client_can_view_and_sign_own_pending_contract(): void
    {
        $client = $this->client();
        $contract = $this->contract($client);

        $this->actingAs($client, 'client')
            ->get(route('client.contracts.show', $contract))
            ->assertOk()
            ->assertSee('确认签署');

        $this->actingAs($client, 'client')
            ->post(route('client.contracts.sign', $contract))
            ->assertRedirect(route('client.contracts.show', $contract))
            ->assertSessionHas('status', '合同已签署');

        $contract->refresh();
        $this->assertSame('signed', $contract->status);
        $this->assertNotNull($contract->signed_at);
        $this->assertNotNull($contract->sign_ip);
    }

    public function test_client_cannot_access_or_sign_other_clients_contract(): void
    {
        $client = $this->client();
        $other = $this->client('contract-other', 'contract-other@example.com');
        $contract = $this->contract($other);

        $this->actingAs($client, 'client')
            ->get(route('client.contracts.show', $contract))
            ->assertForbidden();

        $this->actingAs($client, 'client')
            ->post(route('client.contracts.sign', $contract))
            ->assertForbidden();

        $this->assertSame('pending_signature', $contract->fresh()->status);
    }

    public function test_signed_contract_cannot_be_signed_again(): void
    {
        $client = $this->client();
        $contract = $this->contract($client, ['status' => 'signed', 'signed_at' => now(), 'sign_ip' => '127.0.0.1']);

        $this->actingAs($client, 'client')
            ->post(route('client.contracts.sign', $contract))
            ->assertRedirect(route('client.contracts.show', $contract))
            ->assertSessionHas('error', '当前合同状态不允许签署');

        $this->assertSame('127.0.0.1', $contract->fresh()->sign_ip);
    }

    private function admin(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'contract-admin',
            'email' => 'contract-admin@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);

        Role::query()->firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['super-admin']);

        return $admin;
    }

    private function client(string $username = 'contract-client', string $email = 'contract-client@example.com'): Client
    {
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
        ]);
    }

    private function order(Client $client): Order
    {
        return Order::query()->create([
            'client_id' => $client->id,
            'order_number' => 'ORD-CONTRACT-' . random_int(1000, 9999),
            'status' => 'Pending',
            'amount' => 199,
            'currency_id' => $client->currency_id,
        ]);
    }

    private function contract(Client $client, array $overrides = []): Contract
    {
        return Contract::query()->create(array_merge([
            'client_id' => $client->id,
            'order_id' => $this->order($client)->id,
            'title' => '测试合同',
            'content' => '合同正文',
            'status' => 'pending_signature',
        ], $overrides));
    }
}
