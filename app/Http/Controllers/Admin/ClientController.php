<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\User\Models\Client;
use App\Modules\User\Services\ClientService;
use App\Services\AdminAuditService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $keyword = $this->queryString($request, 'keyword');

        $clients = Client::query()
            ->when($keyword, function ($query, string $keyword) {
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

    public function store(Request $request, ClientService $clients, AdminAuditService $audit)
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:50', 'unique:clients,username'],
            'email' => ['required', 'email', 'max:100', 'unique:clients,email'],
            'password' => ['required', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:50'],
        ]);

        $client = $clients->create($data);
        $audit->record($request, 'client.create', $client, 'success', $data);

        return redirect()->route('admin.clients.show', $client)->with('status', '客户已创建');
    }

    public function update(Request $request, Client $client, ClientService $clients, AdminAuditService $audit)
    {
        $data = $request->validate([
            'username' => ['sometimes', 'required', 'string', 'max:50', 'unique:clients,username,' . $client->id],
            'email' => ['sometimes', 'required', 'email', 'max:100', 'unique:clients,email,' . $client->id],
            'password' => ['nullable', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'integer', Rule::in([0, 1, 2])],
        ]);

        $filtered = collect($data)
            ->reject(fn ($value, string $key) => $key === 'password' && ($value === null || $value === ''))
            ->all();
        $clients->update($client, $filtered);
        $audit->record($request, 'client.update', $client, 'success', $filtered);

        return redirect()->route('admin.clients.show', $client)->with('status', '客户已更新');
    }

    public function destroy(Request $request, Client $client, ClientService $clients, AdminAuditService $audit)
    {
        $clientId = $client->id;

        if (!$clients->delete($client)) {
            $blockingStatuses = $client->hosts()
                ->whereIn('status', ClientService::BLOCKING_HOST_STATUSES)
                ->pluck('status')
                ->unique()
                ->values()
                ->all();

            $audit->record($request, 'client.delete', $client, 'failed', [
                'client_id' => $clientId,
                'blocking_host_statuses' => $blockingStatuses,
            ], '客户存在未完结服务，不能删除');

            return redirect()->route('admin.clients.show', $client)->with('error', '客户存在未完结服务，不能删除');
        }

        $audit->record($request, 'client.delete', $client, 'success', ['client_id' => $clientId]);

        return redirect()->route('admin.clients.index')->with('status', '客户已删除');
    }

    public function addCredit(Request $request, Client $client, ClientService $clients, AdminAuditService $audit)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $success = $clients->addCredit($client, (float) $data['amount'], $data['description'] ?? '后台充值');
        $audit->record($request, 'client.add_credit', $client, $success ? 'success' : 'failed', [
            'amount' => (float) $data['amount'],
            'description' => $data['description'] ?? '后台充值',
            'client_status' => $client->fresh()->status,
        ], $success ? null : '客户状态不允许充值');

        if (!$success) {
            return redirect()->route('admin.clients.show', $client)->with('error', '客户状态不允许充值');
        }

        return redirect()->route('admin.clients.show', $client)->with('status', '余额已充值');
    }

    public function updateCreditLimit(
        Request $request,
        Client $client,
        ClientService $clients,
        AdminAuditService $audit
    ): RedirectResponse {
        $data = $request->validate([
            'credit_limit' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
        ]);

        $limit = (float) $data['credit_limit'];
        $success = $clients->updateCreditLimit($client, $limit);
        $audit->record($request, 'client.update_credit_limit', $client, $success ? 'success' : 'failed', [
            'credit_limit' => round($limit, 2),
        ], $success ? null : '信用额度更新失败');

        if (!$success) {
            return redirect()->route('admin.clients.show', $client)->with('error', '信用额度更新失败');
        }

        return redirect()->route('admin.clients.show', $client)->with('status', '信用额度已更新');
    }

    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
