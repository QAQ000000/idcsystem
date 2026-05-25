<?php

namespace App\Modules\Product\Services;

use App\Modules\Product\Models\Pricing;
use App\Modules\Product\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ProductService
{
    /**
     * 创建产品。
     */
    public function create(array $data): Product
    {
        return DB::transaction(function () use ($data) {
            return Product::create($data);
        });
    }

    /**
     * 更新产品资料。
     */
    public function update(Product $product, array $data): bool
    {
        return DB::transaction(function () use ($product, $data) {
            return $product->update($data);
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
}
