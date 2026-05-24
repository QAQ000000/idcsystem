<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\User\Models\Client;
use App\Modules\User\Services\ClientService;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $clients = Client::query()
            ->when($request->string('keyword')->toString(), function ($query, string $keyword) {
                $query->where('username', 'like', "%{$keyword}%")
                    ->orWhere('email', 'like', "%{$keyword}%");
            })
            ->latest()
            ->paginate(20);

        return view('admin.clients.index', compact('clients'));
    }

    public function show(Client $client)
    {
        $client->load(['orders', 'hosts.product', 'invoices', 'tickets.status', 'loginLogs']);

        return view('admin.clients.show', compact('client'));
    }

    public function store(Request $request, ClientService $clients)
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:50', 'unique:clients,username'],
            'email' => ['required', 'email', 'max:100', 'unique:clients,email'],
            'password' => ['required', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:50'],
        ]);

        $client = $clients->create($data);

        return redirect()->route('admin.clients.show', $client)->with('status', '客户已创建');
    }

    public function update(Request $request, Client $client, ClientService $clients)
    {
        $data = $request->validate([
            'username' => ['sometimes', 'required', 'string', 'max:50', 'unique:clients,username,' . $client->id],
            'email' => ['sometimes', 'required', 'email', 'max:100', 'unique:clients,email,' . $client->id],
            'password' => ['nullable', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'integer'],
        ]);

        $clients->update($client, array_filter($data, fn ($value) => $value !== null && $value !== ''));

        return redirect()->route('admin.clients.show', $client)->with('status', '客户已更新');
    }

    public function destroy(Client $client)
    {
        $client->delete();

        return redirect()->route('admin.clients.index')->with('status', '客户已删除');
    }

    public function addCredit(Request $request, Client $client, ClientService $clients)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $clients->addCredit($client, (float) $data['amount'], $data['description'] ?? '后台充值');

        return redirect()->route('admin.clients.show', $client)->with('status', '余额已充值');
    }
}
