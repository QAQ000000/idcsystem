<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Finance\Models\Credit;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Order\Models\Host;
use App\Modules\Order\Models\Order;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductGroup;
use App\Modules\User\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_export_clients_invoices_hosts_and_credits_as_csv(): void
    {
        $admin = $this->admin();
        $client = $this->client();
        $invoice = $this->invoice($client);
        $host = $this->host($client);
        Credit::query()->create([
            'client_id' => $client->id,
            'type' => 'add',
            'amount' => 50,
            'balance' => 50,
            'description' => '测试充值',
        ]);

        $this->assertCsvContains(route('admin.export.clients'), $admin, ['用户名', $client->username]);
        $this->assertCsvContains(route('admin.export.invoices', ['status' => 'Unpaid']), $admin, ['账单号', $invoice->invoice_number]);
        $this->assertCsvContains(route('admin.export.hosts', ['status' => 'Active']), $admin, ['域名', $host->domain]);
        $this->assertCsvContains(route('admin.export.credits', ['client_id' => $client->id]), $admin, ['余额快照', '测试充值']);
    }

    private function assertCsvContains(string $url, AdminUser $admin, array $needles): void
    {
        $response = $this->actingAs($admin, 'admin')->get($url);
        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));

        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        foreach ($needles as $needle) {
            $this->assertStringContainsString($needle, $content);
        }
    }

    private function admin(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'export-admin',
            'email' => 'export-admin@example.com',
            'password' => Hash::make('password'),
            'status' => 1,
        ]);

        Role::query()->firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['super-admin']);

        return $admin;
    }

    private function client(): Client
    {
        return Client::query()->create([
            'username' => 'export-client',
            'email' => 'export-client@example.com',
            'password' => Hash::make('password'),
            'status' => 1,
            'phone' => '13800138000',
            'company_name' => 'Export Co',
            'credit' => 50,
            'credit_limit' => 100,
        ]);
    }

    private function invoice(Client $client): Invoice
    {
        return Invoice::query()->create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-EXPORT-1',
            'subtotal' => 100,
            'tax' => 0,
            'total' => 100,
            'status' => 'Unpaid',
        ]);
    }

    private function host(Client $client): Host
    {
        $group = ProductGroup::query()->create(['name' => '导出产品组']);
        $product = Product::query()->create([
            'group_id' => $group->id,
            'name' => '导出 VPS',
            'type' => 'vps',
            'stock_control' => false,
        ]);
        $order = Order::query()->create([
            'client_id' => $client->id,
            'order_number' => 'ORD-EXPORT-1',
            'status' => 'Paid',
            'amount' => 100,
        ]);

        return Host::query()->create([
            'client_id' => $client->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'domain' => 'export.example.com',
            'billing_cycle' => 'monthly',
            'first_payment_amount' => 100,
            'recurring_amount' => 100,
            'status' => 'Active',
        ]);
    }
}
