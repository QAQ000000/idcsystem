<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Finance\Models\Currency;
use App\Modules\Finance\Models\TaxRule;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\TaxService;
use App\Modules\User\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TaxRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_tax_service_prefers_state_rule_then_country_rule_then_global_rate(): void
    {
        $client = $this->client(countryCode: 'US', stateCode: 'CA');
        TaxRule::query()->create(['name' => '美国销售税', 'country_code' => 'US', 'rate' => 5, 'active' => true]);
        TaxRule::query()->create(['name' => '加州销售税', 'country_code' => 'US', 'state_code' => 'CA', 'rate' => 8.25, 'active' => true]);

        $taxes = app(TaxService::class);

        $this->assertSame(8.25, $taxes->getRate($client));
        $this->assertSame(8.25, $taxes->calculate($client, 100));

        $client->update(['state_code' => 'NY']);
        $this->assertSame(5.0, $taxes->getRate($client->fresh()));

        config(['billing.tax_rate' => 3.5]);
        $client->update(['country_code' => 'GB', 'state_code' => null]);
        $this->assertSame(3.5, $taxes->getRate($client->fresh()));
    }

    public function test_invoice_generation_uses_tax_rule_snapshot(): void
    {
        $client = $this->client(countryCode: 'CN', stateCode: 'GD');
        TaxRule::query()->create(['name' => '中国增值税', 'country_code' => 'CN', 'rate' => 6, 'active' => true]);
        $rule = TaxRule::query()->create(['name' => '广东服务税', 'country_code' => 'CN', 'state_code' => 'GD', 'rate' => 13, 'active' => true]);

        $invoice = app(InvoiceService::class)->generate($client, [[
            'type' => 'product',
            'description' => '税率测试产品',
            'amount' => 100,
        ]]);

        $this->assertSame('13.00', $invoice->tax_rate);
        $this->assertSame('13.00', $invoice->tax);
        $this->assertSame('113.00', $invoice->total);
        $this->assertSame($rule->id, $invoice->tax_rule_id);
        $this->assertSame('广东服务税', $invoice->tax_rule_name);
    }

    public function test_admin_can_manage_tax_rules(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.tax-rules.store'), [
                'name' => '中国增值税',
                'country_code' => 'cn',
                'state_code' => '',
                'rate' => 13,
                'active' => '1',
            ])
            ->assertRedirect(route('admin.tax-rules.index'))
            ->assertSessionHas('status', '税率规则已创建');

        $rule = TaxRule::query()->where('country_code', 'CN')->firstOrFail();
        $this->assertNull($rule->state_code);
        $this->assertTrue($rule->active);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.tax-rules.update', $rule), [
                'name' => '广东服务税',
                'country_code' => 'CN',
                'state_code' => 'gd',
                'rate' => 9.5,
                'active' => '1',
            ])
            ->assertRedirect(route('admin.tax-rules.index'))
            ->assertSessionHas('status', '税率规则已保存');

        $rule->refresh();
        $this->assertSame('GD', $rule->state_code);
        $this->assertSame('9.50', $rule->rate);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tax-rules.index', ['country_code' => 'CN', 'status' => 'active']))
            ->assertOk()
            ->assertSee('广东服务税');

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.tax-rules.destroy', $rule))
            ->assertRedirect(route('admin.tax-rules.index'))
            ->assertSessionHas('status', '税率规则已删除');

        $this->assertDatabaseMissing('tax_rules', ['id' => $rule->id]);
    }

    public function test_client_profile_saves_region_codes_and_invoice_page_shows_tax_rule_name(): void
    {
        $client = $this->client();
        TaxRule::query()->create(['name' => '广东服务税', 'country_code' => 'CN', 'state_code' => 'GD', 'rate' => 13, 'active' => true]);

        $this->actingAs($client, 'client')
            ->get(route('client.account.profile'))
            ->assertOk()
            ->assertSee('<select class="mt-1 w-full rounded border px-3 py-2" name="country_code">', false)
            ->assertSee('<select class="mt-1 w-full rounded border px-3 py-2" name="state_code">', false);

        $this->actingAs($client, 'client')
            ->put(route('client.account.profile.update'), [
                'phone_code' => '86',
                'phone' => '13800138000',
                'country_code' => 'cn',
                'state_code' => 'gd',
                'locale' => 'zh_CN',
            ])
            ->assertRedirect(route('client.account.profile'));

        $this->assertSame('CN', $client->fresh()->country_code);
        $this->assertSame('GD', $client->fresh()->state_code);

        $invoice = app(InvoiceService::class)->generate($client->fresh(), [[
            'type' => 'product',
            'description' => '税率显示产品',
            'amount' => 100,
        ]]);

        $this->actingAs($client->fresh(), 'client')
            ->get(route('client.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('广东服务税')
            ->assertSee('13%');
    }

    public function test_no_payment_required_invoice_keeps_regional_tax_snapshot(): void
    {
        $client = $this->client(countryCode: 'CN', stateCode: 'GD');
        $rule = TaxRule::query()->create([
            'name' => '广东服务税',
            'country_code' => 'CN',
            'state_code' => 'GD',
            'rate' => 13,
            'active' => true,
        ]);

        $invoice = app(InvoiceService::class)->generateNoPaymentRequired($client, [[
            'type' => 'downgrade',
            'description' => '降配调整',
            'amount' => 0,
        ]]);

        $this->assertSame('13.00', $invoice->tax_rate);
        $this->assertSame($rule->id, $invoice->tax_rule_id);
        $this->assertSame('广东服务税', $invoice->tax_rule_name);
        $this->assertSame('0.00', $invoice->total);
    }

    private function client(?string $countryCode = null, ?string $stateCode = null): Client
    {
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );

        return Client::query()->create([
            'username' => 'tax-client-' . random_int(1000, 9999),
            'email' => 'tax-client-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => $currency->id,
            'country_code' => $countryCode,
            'state_code' => $stateCode,
            'locale' => 'zh_CN',
        ]);
    }

    private function admin(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'tax-admin',
            'email' => 'tax-admin@example.com',
            'password' => Hash::make('password'),
            'status' => 1,
        ]);

        Role::query()->firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['super-admin']);

        return $admin;
    }
}
