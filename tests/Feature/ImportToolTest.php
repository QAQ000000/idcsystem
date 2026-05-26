<?php

namespace Tests\Feature;

use App\Jobs\ProcessImportJob;
use App\Models\ImportJob;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Finance\Models\Currency;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductGroup;
use App\Modules\User\Models\Client;
use App\Services\ImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ImportToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_download_templates_and_create_import_job(): void
    {
        Storage::fake('local');
        Bus::fake();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.imports.template', 'clients'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $file = UploadedFile::fake()->createWithContent('clients.csv', implode("\n", [
            'username,email,password,phone,company,address,country_code,state_code',
            'imported,imported@example.com,password123,13800138000,测试公司,测试地址,CN,BJ',
        ]));

        $response = $this->actingAs($admin, 'admin')
            ->post(route('admin.imports.store', 'clients'), [
                'file' => $file,
            ]);

        $job = ImportJob::query()->firstOrFail();
        $response->assertRedirect(route('admin.imports.show', $job));
        $this->assertSame('clients', $job->type);
        Storage::disk('local')->assertExists($job->file_path);
        Bus::assertDispatched(ProcessImportJob::class);
    }

    public function test_import_service_imports_clients_and_records_row_errors(): void
    {
        $admin = $this->admin();
        $job = $this->csvJob($admin, 'clients', [
            ['username', 'email', 'password', 'phone', 'company', 'address', 'country_code', 'state_code'],
            ['alice', 'alice@example.com', 'password123', '13800138000', 'A 公司', '地址', 'CN', 'BJ'],
            ['alice', 'bad-email', 'short', '', '', '', '', ''],
        ]);

        app(ImportService::class)->import($job);

        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertSame(2, $job->total_rows);
        $this->assertSame(1, $job->success_count);
        $this->assertSame(1, $job->failed_count);
        $this->assertDatabaseHas('clients', [
            'username' => 'alice',
            'email' => 'alice@example.com',
            'status' => 1,
        ]);
        $this->assertSame(3, $job->errors[0]['line']);
    }

    public function test_import_service_imports_products_and_invoices(): void
    {
        $admin = $this->admin();
        $currency = $this->currency();
        $group = ProductGroup::query()->create(['name' => '导入产品组']);
        $client = $this->client($currency);

        $productJob = $this->csvJob($admin, 'products', [
            ['name', 'description', 'group_id', 'price', 'billing_cycle', 'stock_qty'],
            ['导入虚拟主机', '基础套餐', (string) $group->id, '99.00', 'monthly', '100'],
        ]);
        app(ImportService::class)->import($productJob);

        $productJob->refresh();
        $product = Product::query()->where('name', '导入虚拟主机')->firstOrFail();
        $this->assertSame('completed', $productJob->status);
        $this->assertDatabaseHas('pricings', [
            'type' => 'product',
            'rel_id' => $product->id,
            'currency_id' => $currency->id,
            'monthly' => 99,
        ]);

        $invoiceJob = $this->csvJob($admin, 'invoices', [
            ['client_id', 'invoice_number', 'amount', 'status', 'due_date', 'paid_at', 'payment_method', 'description'],
            [(string) $client->id, 'INV-IMPORT-TEST', '120.50', 'Paid', '2026-05-20', '2026-05-21 10:00:00', 'manual', '迁移账单'],
        ]);
        app(ImportService::class)->import($invoiceJob);

        $invoiceJob->refresh();
        $invoice = Invoice::query()->where('invoice_number', 'INV-IMPORT-TEST')->firstOrFail();
        $this->assertSame('completed', $invoiceJob->status);
        $this->assertSame('Paid', $invoice->status);
        $this->assertSame('120.50', (string) $invoice->total);
        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $invoice->id,
            'type' => 'import',
            'description' => '迁移账单',
        ]);
    }

    public function test_admin_can_view_import_job_result(): void
    {
        $admin = $this->admin();
        $job = ImportJob::query()->create([
            'admin_user_id' => $admin->id,
            'type' => 'clients',
            'file_path' => 'imports/test.csv',
            'status' => 'failed',
            'total_rows' => 1,
            'failed_count' => 1,
            'errors' => [['line' => 2, 'message' => '邮箱已存在']],
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.imports.show', $job))
            ->assertOk()
            ->assertSee('导入任务 #' . $job->id)
            ->assertSee('邮箱已存在');
    }

    private function admin(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'import-admin-' . random_int(1000, 9999),
            'email' => 'import-admin-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);

        Role::query()->firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['super-admin']);

        return $admin;
    }

    private function csvJob(AdminUser $admin, string $type, array $rows): ImportJob
    {
        $path = 'imports/' . $type . '-' . random_int(1000, 9999) . '.csv';
        Storage::disk('local')->put($path, $this->csv($rows));

        return ImportJob::query()->create([
            'admin_user_id' => $admin->id,
            'type' => $type,
            'file_path' => $path,
            'status' => 'pending',
        ]);
    }

    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);

        return $contents;
    }

    private function currency(): Currency
    {
        return Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );
    }

    private function client(Currency $currency): Client
    {
        return Client::query()->create([
            'username' => 'import-client-' . random_int(1000, 9999),
            'email' => 'import-client-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => $currency->id,
        ]);
    }
}
