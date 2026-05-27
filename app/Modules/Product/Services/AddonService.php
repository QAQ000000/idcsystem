<?php

namespace App\Modules\Product\Services;

use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Order\Models\Host;
use App\Modules\Order\Models\HostAddon;
use App\Modules\Product\Models\ProductAddon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AddonService
{
    public function attach(Host $host, ProductAddon $addon): HostAddon
    {
        return DB::transaction(function () use ($host, $addon): HostAddon {
            $lockedAddon = ProductAddon::query()->whereKey($addon->id)->lockForUpdate()->firstOrFail();
            if (!$lockedAddon->active || (int) $lockedAddon->product_id !== (int) $host->product_id) {
                throw new RuntimeException('附加项不可用于当前服务。');
            }

            if ($lockedAddon->stock_qty !== null && $lockedAddon->stock_qty <= 0) {
                throw new RuntimeException('附加项库存不足。');
            }

            if ($lockedAddon->stock_qty !== null) {
                $lockedAddon->decrement('stock_qty');
            }

            $hostAddon = HostAddon::query()->create([
                'host_id' => $host->id,
                'addon_id' => $lockedAddon->id,
                'price' => $lockedAddon->price,
                'billing_cycle' => $lockedAddon->billing_cycle,
                'status' => 'Active',
                'next_due_date' => $lockedAddon->billing_cycle === 'recurring' ? $host->next_due_date : null,
            ]);

            DB::afterCommit(fn () => app(ProductCacheService::class)->invalidateProduct((int) $lockedAddon->product_id));

            return $hostAddon;
        });
    }

    public function detach(HostAddon $hostAddon): bool
    {
        return $hostAddon->update(['status' => 'Terminated']);
    }

    public function createAttachInvoice(Host $host, ProductAddon $addon): Invoice
    {
        $host->loadMissing('client');
        if (!$host->client || $host->client->trashed() || !$host->client->isActive()) {
            throw new RuntimeException('客户账号状态不允许购买附加项。');
        }

        if (!in_array($host->status, ['Active', 'Suspended'], true)) {
            throw new RuntimeException('当前服务状态不允许添加附加项。');
        }

        if (!$addon->active || (int) $addon->product_id !== (int) $host->product_id) {
            throw new RuntimeException('附加项不可用于当前服务。');
        }

        $hasActive = HostAddon::query()
            ->where('host_id', $host->id)
            ->where('addon_id', $addon->id)
            ->where('status', '!=', 'Terminated')
            ->exists();
        if ($hasActive) {
            throw new RuntimeException('该附加项已存在。');
        }

        $hasUnpaid = \App\Modules\Finance\Models\InvoiceItem::query()
            ->where('type', 'addon')
            ->where('rel_id', $addon->id)
            ->where('meta->host_id', $host->id)
            ->whereHas('invoice', fn ($query) => $query->where('status', 'Unpaid'))
            ->exists();
        if ($hasUnpaid) {
            throw new RuntimeException('该附加项已有未支付账单。');
        }

        return app(InvoiceService::class)->generate($host->client, [[
            'type' => 'addon',
            'description' => '添加附加项：' . $addon->name,
            'amount' => (float) $addon->price,
            'rel_id' => $addon->id,
            'meta' => [
                'host_id' => $host->id,
                'addon_id' => $addon->id,
                'billing_cycle' => $addon->billing_cycle,
                'action' => 'attach',
            ],
        ]]);
    }

    public function renew(HostAddon $hostAddon): Invoice
    {
        $hostAddon->loadMissing(['host.client', 'addon']);
        if ($hostAddon->status !== 'Active' || $hostAddon->billing_cycle !== 'recurring') {
            throw new RuntimeException('附加项状态不允许续费。');
        }

        return app(InvoiceService::class)->generate($hostAddon->host->client, [[
            'type' => 'addon',
            'description' => '附加项续费：' . ($hostAddon->addon?->name ?: 'Addon #' . $hostAddon->id),
            'amount' => (float) $hostAddon->price,
            'rel_id' => $hostAddon->id,
        ]]);
    }
}
