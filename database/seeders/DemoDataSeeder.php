<?php

namespace Database\Seeders;

use App\Modules\Finance\Models\Currency;
use App\Modules\Product\Models\Pricing;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductGroup;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $currencyId = (int) Currency::query()->where('code', 'CNY')->value('id');

        if ($currencyId === 0) {
            return;
        }

        $groups = $this->seedGroups();
        $this->seedProducts($groups, $currencyId);
    }

    private function seedGroups(): array
    {
        $groups = [];

        foreach ($this->groups() as $group) {
            $groups[$group['key']] = ProductGroup::query()->updateOrCreate(
                ['name' => $group['name']],
                [
                    'parent_id' => 0,
                    'description' => $group['description'],
                    'sort_order' => $group['sort_order'],
                    'hidden' => false,
                ]
            );
        }

        return $groups;
    }

    private function seedProducts(array $groups, int $currencyId): void
    {
        foreach ($this->products() as $item) {
            $product = Product::query()->updateOrCreate(
                ['name' => $item['name']],
                [
                    'group_id' => $groups[$item['group_key']]->id,
                    'description' => $item['description'],
                    'type' => $item['type'],
                    'pay_type' => 'recurring',
                    'pay_method' => 'prepaid',
                    'auto_setup' => 'manual',
                    'server_type' => $item['server_type'],
                    'server_group_id' => 0,
                    'stock_control' => true,
                    'stock_qty' => $item['stock_qty'],
                    'domain_config' => ['required' => $item['domain_required']],
                    'password_config' => ['required' => true, 'length' => 12],
                    'hidden' => false,
                    'retired' => false,
                    'is_featured' => $item['is_featured'],
                    'sort_order' => $item['sort_order'],
                    'api_type' => null,
                    'upstream_api_id' => 0,
                    'upstream_product_id' => 0,
                    'upstream_price_type' => 'percent',
                    'upstream_price_value' => 120.00,
                ]
            );

            Pricing::query()->updateOrCreate(
                ['type' => 'product', 'rel_id' => $product->id, 'currency_id' => $currencyId],
                $item['pricing']
            );
        }
    }

    private function groups(): array
    {
        return [
            [
                'key' => 'hosting',
                'name' => '虚拟主机',
                'description' => '适合企业官网、博客和轻量应用。',
                'sort_order' => 10,
            ],
            [
                'key' => 'cloud',
                'name' => '云服务器',
                'description' => '弹性计算实例，适合业务系统和开发测试。',
                'sort_order' => 20,
            ],
            [
                'key' => 'dedicated',
                'name' => '独立服务器',
                'description' => '独享物理资源，适合高性能业务。',
                'sort_order' => 30,
            ],
        ];
    }

    private function products(): array
    {
        return [
            [
                'group_key' => 'hosting',
                'name' => '基础虚拟主机',
                'description' => '1 核共享 CPU、2GB 存储、适合小型网站。',
                'type' => 'hosting',
                'server_type' => 'shared-hosting',
                'stock_qty' => 100,
                'domain_required' => true,
                'is_featured' => true,
                'sort_order' => 10,
                'pricing' => [
                    'monthly' => 19.00,
                    'quarterly' => 54.00,
                    'semiannually' => 99.00,
                    'annually' => 188.00,
                    'biennially' => 358.00,
                    'triennially' => 498.00,
                ],
            ],
            [
                'group_key' => 'cloud',
                'name' => '标准云服务器',
                'description' => '2 核 CPU、4GB 内存、50GB SSD，适合通用业务。',
                'type' => 'vps',
                'server_type' => 'cloud-vm',
                'stock_qty' => 50,
                'domain_required' => false,
                'is_featured' => true,
                'sort_order' => 20,
                'pricing' => [
                    'monthly' => 89.00,
                    'quarterly' => 255.00,
                    'semiannually' => 498.00,
                    'annually' => 948.00,
                    'biennially' => 1798.00,
                    'triennially' => 2498.00,
                ],
            ],
            [
                'group_key' => 'dedicated',
                'name' => '入门独立服务器',
                'description' => '独享 CPU、32GB 内存、1TB SSD，适合高负载应用。',
                'type' => 'dedicated',
                'server_type' => 'bare-metal',
                'stock_qty' => 10,
                'domain_required' => false,
                'is_featured' => false,
                'sort_order' => 30,
                'pricing' => [
                    'monthly' => 699.00,
                    'quarterly' => 1999.00,
                    'semiannually' => 3899.00,
                    'annually' => 7399.00,
                    'biennially' => 13999.00,
                    'triennially' => 19999.00,
                ],
            ],
        ];
    }
}
