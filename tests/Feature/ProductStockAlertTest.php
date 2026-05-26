<?php

namespace Tests\Feature;

use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductGroup;
use App\Modules\Product\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductStockAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_alert_is_created_when_stock_reaches_threshold(): void
    {
        $product = $this->product(['stock_qty' => 2, 'stock_alert_threshold' => 2, 'stock_alert_enabled' => true]);

        $created = app(ProductService::class)->checkStockAlerts();

        $this->assertSame(1, $created);
        $this->assertDatabaseHas('product_stock_alerts', [
            'product_id' => $product->id,
            'stock_qty' => 2,
            'threshold' => 2,
        ]);
    }

    public function test_stock_alert_is_not_duplicated_and_resolves_when_stock_recovers(): void
    {
        $service = app(ProductService::class);
        $product = $this->product(['stock_qty' => 1, 'stock_alert_threshold' => 2, 'stock_alert_enabled' => true]);

        $this->assertSame(1, $service->checkStockAlerts());
        $this->assertSame(0, $service->checkStockAlerts());

        $product->update(['stock_qty' => 5]);
        $service->checkProductStockAlert($product->fresh());

        $this->assertNotNull($product->stockAlerts()->first()->fresh()->resolved_at);
    }

    public function test_decrement_stock_triggers_alert(): void
    {
        $product = $this->product(['stock_qty' => 3, 'stock_alert_threshold' => 2, 'stock_alert_enabled' => true]);

        $this->assertTrue(app(ProductService::class)->decrementStock($product, 1));

        $this->assertDatabaseHas('product_stock_alerts', [
            'product_id' => $product->id,
            'stock_qty' => 2,
        ]);
    }

    public function test_create_triggers_alert_and_disabling_resolves_active_alert(): void
    {
        $group = ProductGroup::query()->create(['name' => '新建低库存分组']);
        $service = app(ProductService::class);

        $product = $service->create([
            'group_id' => $group->id,
            'name' => '新建低库存产品',
            'type' => 'hosting',
            'pay_type' => 'recurring',
            'pay_method' => 'prepaid',
            'auto_setup' => 'manual',
            'stock_control' => true,
            'stock_qty' => 1,
            'stock_alert_threshold' => 2,
            'stock_alert_enabled' => true,
        ]);

        $this->assertDatabaseHas('product_stock_alerts', [
            'product_id' => $product->id,
            'stock_qty' => 1,
            'threshold' => 2,
            'resolved_at' => null,
        ]);

        $this->assertTrue($service->update($product->fresh(), ['stock_alert_enabled' => false]));

        $this->assertNotNull($product->stockAlerts()->first()->fresh()->resolved_at);
    }

    private function product(array $overrides = []): Product
    {
        $group = ProductGroup::query()->create(['name' => '默认分组']);

        return Product::query()->create(array_merge([
            'group_id' => $group->id,
            'name' => '库存预警产品',
            'type' => 'hosting',
            'pay_type' => 'recurring',
            'pay_method' => 'prepaid',
            'auto_setup' => 'manual',
            'stock_control' => true,
            'stock_qty' => 10,
            'hidden' => false,
            'retired' => false,
        ], $overrides));
    }
}
