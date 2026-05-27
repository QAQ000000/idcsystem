<?php

namespace App\Modules\Product\Services;

use App\Modules\Product\Models\Product;
use App\Services\Cache\CacheService;
use Illuminate\Database\Eloquent\Collection;

class ProductCacheService extends CacheService
{
    protected string $prefix = 'product';

    protected int $ttl = 1800;

    public function getAvailableProducts(): Collection
    {
        return $this->remember('available', function (): Collection {
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
        });
    }

    public function getProduct(int $id): ?Product
    {
        return $this->remember("detail:{$id}", function () use ($id): ?Product {
            return Product::query()
                ->with([
                    'group',
                    'pricings',
                    'customFields' => fn ($query) => $query
                        ->where('admin_only', false)
                        ->orderBy('sort_order')
                        ->orderBy('id'),
                    'addons' => fn ($query) => $query
                        ->where('active', true)
                        ->where(function ($query): void {
                            $query->whereNull('stock_qty')->orWhere('stock_qty', '>', 0);
                        })
                        ->orderBy('sort_order')
                        ->orderBy('id'),
                ])
                ->find($id);
        });
    }

    public function invalidateProduct(int $id): void
    {
        $this->forget("detail:{$id}");
        $this->forget('available');
    }

    public function invalidateAll(): void
    {
        $this->forget('available');
    }
}
