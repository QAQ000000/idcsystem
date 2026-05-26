<?php

namespace App\Modules\Product\Services;

use App\Models\Plugin;
use App\Modules\Product\Models\Pricing;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductGroup;
use App\Modules\Product\Models\ProductStockAlert;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ProductService
{
    /**
     * 创建产品。
     */
    public function create(array $data): Product
    {
        return DB::transaction(function () use ($data) {
            $product = Product::create($this->normalizeProductPayload($data));
            $this->checkProductStockAlert($product);

            return $product;
        });
    }

    /**
     * 更新产品资料。
     */
    public function update(Product $product, array $data): bool
    {
        return DB::transaction(function () use ($product, $data) {
            $updated = $product->update($this->normalizeProductPayload($data, $product));
            if ($updated) {
                $product->refresh();
                $this->checkProductStockAlert($product);
            }

            return $updated;
        });
    }

    /**
     * 删除产品。
     */
    public function delete(Product $product): bool
    {
        return DB::transaction(function () use ($product) {
            if ($product->hosts()->exists()) {
                return false;
            }

            Pricing::query()
                ->where('type', 'product')
                ->where('rel_id', $product->id)
                ->delete();

            DB::table('custom_fields')
                ->where('type', 'product')
                ->where('rel_id', $product->id)
                ->delete();

            return (bool) $product->delete();
        });
    }

    /**
     * 检查产品是否仍有库存。
     */
    public function checkStock(Product $product): bool
    {
        if (!$product->stock_control) {
            return true;
        }

        return (int) $product->stock_qty > 0;
    }

    public function isPurchasable(Product $product, int $qty = 1): bool
    {
        if ($product->hidden || $product->retired) {
            return false;
        }

        if ($qty < 1) {
            return false;
        }

        if (!$product->stock_control) {
            return true;
        }

        return (int) $product->stock_qty >= $qty;
    }

    /**
     * 扣减库存，库存不足时返回 false。
     */
    public function decrementStock(Product $product, int $qty = 1): bool
    {
        if ($qty < 1) {
            return false;
        }

        if (!$product->stock_control) {
            return true;
        }

        return DB::transaction(function () use ($product, $qty) {
            $affected = Product::query()
                ->whereKey($product->id)
                ->where('stock_control', true)
                ->where('stock_qty', '>=', $qty)
                ->decrement('stock_qty', $qty);

            if ($affected > 0) {
                $product->refresh();
                $this->checkProductStockAlert($product);
            }

            return $affected > 0;
        });
    }

    /**
     * 获取前台可购买产品列表。
     */
    public function getAvailableProducts(): Collection
    {
        return Product::query()
            ->with(['group', 'pricings'])
            ->where('hidden', false)
            ->where('retired', false)
            ->where(function ($query) {
                $query->where('stock_control', false)
                    ->orWhere('stock_qty', '>', 0);
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function checkStockAlerts(): int
    {
        $created = 0;

        Product::query()
            ->where('stock_control', true)
            ->where('stock_alert_enabled', true)
            ->chunkById(100, function ($products) use (&$created): void {
                foreach ($products as $product) {
                    if ($this->checkProductStockAlert($product)) {
                        $created++;
                    }
                }
            });

        return $created;
    }

    public function checkProductStockAlert(Product $product): bool
    {
        return DB::transaction(function () use ($product): bool {
            $lockedProduct = Product::query()->whereKey($product->id)->lockForUpdate()->first();
            if (!$lockedProduct) {
                return false;
            }

            $activeAlert = ProductStockAlert::query()
                ->where('product_id', $lockedProduct->id)
                ->whereNull('resolved_at')
                ->lockForUpdate()
                ->first();

            if (!$lockedProduct->stock_control
                || !$lockedProduct->stock_alert_enabled
                || (int) $lockedProduct->stock_alert_threshold <= 0) {
                if ($activeAlert) {
                    $activeAlert->update(['resolved_at' => now()]);
                }

                return false;
            }

            if ((int) $lockedProduct->stock_qty <= (int) $lockedProduct->stock_alert_threshold) {
                if ($activeAlert) {
                    return false;
                }

                ProductStockAlert::query()->create([
                    'product_id' => $lockedProduct->id,
                    'stock_qty' => (int) $lockedProduct->stock_qty,
                    'threshold' => (int) $lockedProduct->stock_alert_threshold,
                    'triggered_at' => now(),
                ]);

                return true;
            }

            if ($activeAlert) {
                $activeAlert->update(['resolved_at' => now()]);
            }

            return false;
        });
    }

    private function normalizeProductPayload(array $data, ?Product $product = null): array
    {
        if (!$product && empty($data['group_id'])) {
            throw new InvalidArgumentException('产品分组不能为空。');
        }

        if (array_key_exists('group_id', $data) && !ProductGroup::query()->whereKey((int) $data['group_id'])->exists()) {
            throw new InvalidArgumentException('产品分组不存在。');
        }

        if (!$product && trim((string) ($data['name'] ?? '')) === '') {
            throw new InvalidArgumentException('产品名称不能为空。');
        }

        if (!$product && trim((string) ($data['type'] ?? '')) === '') {
            throw new InvalidArgumentException('产品类型不能为空。');
        }

        if (array_key_exists('stock_qty', $data)) {
            if (!is_numeric($data['stock_qty']) || (int) $data['stock_qty'] < 0) {
                throw new InvalidArgumentException('产品库存不能为负数。');
            }

            $data['stock_qty'] = (int) $data['stock_qty'];
        }

        if (array_key_exists('stock_alert_threshold', $data)) {
            if (!is_numeric($data['stock_alert_threshold']) || (int) $data['stock_alert_threshold'] < 0) {
                throw new InvalidArgumentException('库存预警阈值不能为负数。');
            }

            $data['stock_alert_threshold'] = (int) $data['stock_alert_threshold'];
        }

        foreach (['stock_control', 'stock_alert_enabled', 'hidden', 'retired', 'is_featured'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = filter_var($data[$field], FILTER_VALIDATE_BOOLEAN);
            }
        }

        if (array_key_exists('server_type', $data)) {
            $data['server_type'] = $this->normalizeServerType($data['server_type'], $product);
        }

        return $data;
    }

    private function normalizeServerType(mixed $serverType, ?Product $product): ?string
    {
        $serverType = trim((string) $serverType);
        if ($serverType === '') {
            return null;
        }

        $exists = Plugin::query()
            ->where('type', 'server')
            ->where('name', $serverType)
            ->where(function ($query) use ($serverType, $product) {
                $query->where('status', 1);

                if ($product && (string) $product->server_type === $serverType) {
                    $query->orWhere('name', $serverType);
                }
            })
            ->exists();

        if (!$exists) {
            throw new InvalidArgumentException('服务器模块不可用。');
        }

        return $serverType;
    }
}
