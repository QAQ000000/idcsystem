<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plugin;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Order\Models\Order;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\User\Models\Client;

class DashboardController extends Controller
{
    public function index()
    {
        return view('admin.dashboard', [
            'stats' => [
                'clients' => Client::query()->count(),
                'orders' => Order::query()->count(),
                'unpaid_invoices' => Invoice::query()->where('status', 'Unpaid')->count(),
                'tickets' => Ticket::query()->count(),
                'plugins' => Plugin::query()->count(),
            ],
            'recentOrders' => Order::query()->with('client')->latest()->limit(8)->get(),
            'recentTickets' => Ticket::query()->with(['client', 'status'])->latest()->limit(8)->get(),
        ]);
    }
}
