<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
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
        ]);
    }
}
