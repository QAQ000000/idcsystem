<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\User\Models\Client;
use App\Modules\User\Models\ClientTag;
use App\Modules\User\Services\ClientTagService;
use App\Modules\User\Services\ClientService;
use App\Services\AdminAuditService;
use App\Services\MailService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $keyword = $this->queryString($request, 'keyword');
        $tagValue = $request->query('tag_id');
        $tagId = is_scalar($tagValue) ? (int) $tagValue : null;
        $tagId = $tagId > 0 ? $tagId : null;
        $creditLevel = $this->queryString($request, 'credit_level');
        $creditLevel = in_array($creditLevel, ['Excellent', 'Good', 'Fair', 'Poor'], true) ? $creditLevel : null;
        $sort = $this->queryString($request, 'sort');

        $clients = Client::query()
            ->with('tags')
            ->when($keyword, function ($query, string $keyword) {
                $query->where('username', 'like', "%{$keyword}%")
                    ->orWhere('email', 'like', "%{$keyword}%");
            })
            ->when($tagId, fn ($query) => $query->whereHas('tags', fn ($query) => $query->where('client_tags.id', $tagId)))
            ->when($creditLevel, fn ($query) => $query->where('credit_level', $creditLevel))
            ->when($sort === 'credit_score_asc', fn ($query) => $query->orderBy('credit_score'))
            ->when($sort === 'credit_score_desc', fn ($query) => $query->orderByDesc('credit_score'))
            ->when(!in_array($sort, ['credit_score_asc', 'credit_score_desc'], true), fn ($query) => $query->latest())
            ->paginate(20)
            ->withQueryString();
        $tags = ClientTag::query()->orderBy('name')->get();

        return view('admin.clients.index', compact('clients', 'tags', 'tagId', 'keyword', 'creditLevel', 'sort'));
    }

    public function bulkAction(Request $request, ClientService $clients, AdminAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['suspend', 'activate', 'add_credit', 'send_email'])],
            'client_ids' => ['required', 'array', 'min:1', 'max:200'],
            'client_ids.*' => ['integer', Rule::exists('clients', 'id')],
            'amount' => ['required_if:action,add_credit', 'nullable', 'numeric', 'min:0.01', 'max:99999'],
            'description' => ['nullable', 'string', 'max:255'],
            'subject' => ['required_if:action,send_email', 'nullable', 'string', 'max:200'],
            'body' => ['required_if:action,send_email', 'nullable', 'string', 'max:5000'],
        ]);

        $count = 0;
        foreach (Client::query()->whereIn('id', $data['client_ids'])->cursor() as $client) {
            $success = match ($data['action']) {
                'suspend' => $clients->suspend($client),
                'activate' => $clients->activate($client),
                'add_credit' => $clients->addCredit($client, (float) $data['amount'], $data['description'] ?? '批量充值'),
                'send_email' => $this->sendBulkEmail($client, (string) $data['subject'], (string) $data['body']),
            };

            if ($success) {
                $count++;
            }
        }

        $audit->record($request, 'client.bulk_' . $data['action'], null, 'success', [
            'client_ids' => array_values($data['client_ids']),
            'count' => $count,
        ]);

        return back()->with('status', "批量操作完成，成功处理 {$count} 个客户");
    }

    public function show(Client $client)
    {
        $client->load(['orders', 'hosts.product', 'invoices', 'tickets.status', 'loginLogs', 'tags', 'creditScoreLogs']);
        $tags = ClientTag::query()->orderBy('name')->get();

        return view('admin.clients.show', compact('client', 'tags'));
    }

    public function creditScoreLogs(Client $client)
    {
        $logs = $client->creditScoreLogs()->latest('created_at')->paginate(20);

        return view('admin.clients.credit-score-logs', compact('client', 'logs'));
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

    public function unlock(Request $request, Client $client, AdminAuditService $audit): RedirectResponse
    {
        $client->forceFill(['locked_until' => null])->save();
        $audit->record($request, 'client.unlock', $client, 'success', [
            'client_id' => $client->id,
        ]);

        return redirect()->route('admin.clients.show', $client)->with('status', '账户已解锁');
    }

    public function attachTag(Request $request, Client $client, ClientTagService $tags, AdminAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'client_tag_id' => ['required', 'integer', Rule::exists('client_tags', 'id')],
        ]);

        $tag = ClientTag::query()->findOrFail((int) $data['client_tag_id']);
        $tags->attachTag($client, $tag);
        $audit->record($request, 'client.tag_attach', $client, 'success', [
            'client_tag_id' => $tag->id,
            'tag' => $tag->slug,
        ]);

        return redirect()->route('admin.clients.show', $client)->with('status', '客户标签已添加');
    }

    public function detachTag(Request $request, Client $client, ClientTag $tag, ClientTagService $tags, AdminAuditService $audit): RedirectResponse
    {
        $tags->detachTag($client, $tag);
        $audit->record($request, 'client.tag_detach', $client, 'success', [
            'client_tag_id' => $tag->id,
            'tag' => $tag->slug,
        ]);

        return redirect()->route('admin.clients.show', $client)->with('status', '客户标签已移除');
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

    private function sendBulkEmail(Client $client, string $subject, string $body): bool
    {
        if ($client->trashed() || !$client->isActive() || empty($client->email)) {
            return false;
        }

        return app(MailService::class)->send((string) $client->email, $subject, $body, [
            'template' => 'custom_email',
            'payload' => [
                'client_name' => $client->username,
                'subject' => $subject,
                'body' => $body,
            ],
        ]);
    }
}
