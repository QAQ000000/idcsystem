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
        $ids = $this->remember('available:ids', function (): array {
            return Product::query()
                ->where('hidden', false)
                ->where('retired', false)
                ->where(function ($query) {
                    $query->where('stock_control', false)
                        ->orWhere('stock_qty', '>', 0);
                })
                ->orderBy('sort_order')
                ->orderBy('id')
                ->pluck('id')
                ->all();
        });

        return $this->productsByIds($ids, ['group', 'pricings']);
    }

    public function getProduct(int $id): ?Product
    {
        $exists = $this->remember("detail:{$id}:exists", fn (): bool => Product::query()->whereKey($id)->exists());
        if (!$exists) {
            return null;
        }

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
    }

    public function invalidateProduct(int $id): void
    {
        $this->forget("detail:{$id}:exists");
        $this->forget('available:ids');
    }

    public function invalidateAll(): void
    {
        $this->forget('available:ids');
    }

    private function productsByIds(array $ids, array $relations): Collection
    {
        if ($ids === []) {
            return new Collection();
        }

        $products = Product::query()
            ->with($relations)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        return new Collection(collect($ids)
            ->map(fn (int $id) => $products->get($id))
            ->filter()
            ->values()
            ->all());
    }
}
