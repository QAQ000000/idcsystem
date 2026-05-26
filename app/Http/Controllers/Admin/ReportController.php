<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceItem;
use App\Modules\Order\Models\Host;
use App\Modules\Product\Models\Product;
use App\Modules\User\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(): View
    {
        return view('admin.reports.index');
    }

    public function revenue(): View
    {
        $rows = Invoice::query()
            ->selectRaw($this->monthExpression('paid_at') . ' as period, SUM(total) as total')
            ->where('status', 'Paid')
            ->whereNotNull('paid_at')
            ->groupBy('period')
            ->orderBy('period')
            ->limit(24)
            ->get();

        return view('admin.reports.revenue', [
            'labels' => $rows->pluck('period')->values(),
            'values' => $rows->pluck('total')->map(fn ($value) => round((float) $value, 2))->values(),
        ]);
    }

    public function clients(): View
    {
        $rows = Client::query()
            ->selectRaw($this->monthExpression('created_at') . ' as period, COUNT(*) as total')
            ->groupBy('period')
            ->orderBy('period')
            ->limit(24)
            ->get();

        return view('admin.reports.clients', [
            'labels' => $rows->pluck('period')->values(),
            'values' => $rows->pluck('total')->map(fn ($value) => (int) $value)->values(),
        ]);
    }

    public function hosts(): View
    {
        $rows = Host::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->orderBy('status')
            ->get();

        return view('admin.reports.hosts', [
            'labels' => $rows->pluck('status')->values(),
            'values' => $rows->pluck('total')->map(fn ($value) => (int) $value)->values(),
        ]);
    }

    public function products(): View
    {
        $rows = InvoiceItem::query()
            ->selectRaw('rel_id as product_id, COUNT(*) as sales_count, SUM(amount) as revenue')
            ->whereIn('type', ['product', 'renewal'])
            ->where('amount', '>', 0)
            ->whereHas('invoice', fn ($query) => $query->where('status', 'Paid'))
            ->groupBy('rel_id')
            ->orderByDesc('revenue')
            ->limit(20)
            ->get();

        $products = Product::query()
            ->whereIn('id', $rows->pluck('product_id')->map(fn ($id) => (int) $id)->all())
            ->get(['id', 'name'])
            ->keyBy('id');
        $items = $rows->map(function ($row) use ($products) {
            $product = $products->get((int) $row->product_id);

            return [
                'name' => $product?->name ?: '产品 #' . $row->product_id,
                'sales_count' => (int) $row->sales_count,
                'revenue' => round((float) $row->revenue, 2),
            ];
        })->values();

        return view('admin.reports.products', [
            'items' => $items,
            'labels' => $items->pluck('name'),
            'values' => $items->pluck('revenue'),
        ]);
    }

    private function monthExpression(string $column): string
    {
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'sqlite' => "strftime('%Y-%m', {$column})",
            'pgsql' => "to_char({$column}, 'YYYY-MM')",
            default => "DATE_FORMAT({$column}, '%Y-%m')",
        };
    }
}
