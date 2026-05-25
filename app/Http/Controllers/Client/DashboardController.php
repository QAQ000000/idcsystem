<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Order\Models\Host;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function index()
    {
        $client = Auth::guard('client')->user();

        if (!$client) {
            return redirect()->route('client.login');
        }

        return view('theme::dashboard', [
            'client' => $client,
            'hosts' => Host::query()->with('product')->where('client_id', $client->id)->latest()->limit(8)->get(),
            'invoices' => Invoice::query()->where('client_id', $client->id)->latest()->limit(8)->get(),
            'tickets' => Ticket::query()->with('status')->where('client_id', $client->id)->latest()->limit(8)->get(),
            'announcements' => Announcement::query()->visible()->latest()->limit(5)->get(),
            'upcomingRenewals' => Host::query()
                ->with('product')
                ->where('client_id', $client->id)
                ->where('status', 'Active')
                ->where('next_due_date', '<=', now()->addDays(30))
                ->orderBy('next_due_date')
                ->limit(5)
                ->get(),
            'unpaidInvoices' => Invoice::query()
                ->where('client_id', $client->id)
                ->where('status', 'Unpaid')
                ->orderBy('due_date')
                ->limit(5)
                ->get(),
            'recentCredits' => $client->credits()->latest()->limit(5)->get(),
            'monthlySpend' => (float) Invoice::query()
                ->where('client_id', $client->id)
                ->where('status', 'Paid')
                ->whereMonth('paid_at', now()->month)
                ->whereYear('paid_at', now()->year)
                ->sum('total'),
        ]);
    }
}
