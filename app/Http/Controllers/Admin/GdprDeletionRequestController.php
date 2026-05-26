<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DataDeletionRequest;
use App\Services\AdminAuditService;
use App\Services\GdprService;
use Illuminate\Http\Request;

class GdprDeletionRequestController extends Controller
{
    public function index(Request $request)
    {
        $status = $this->queryString($request, 'status');
        $requests = DataDeletionRequest::query()
            ->with('client')
            ->when($status, fn ($query, string $value) => $query->where('status', $value))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.gdpr.deletion-requests', [
            'requests' => $requests,
            'statuses' => ['pending', 'approved', 'rejected', 'completed'],
            'status' => $status,
        ]);
    }

    public function approve(Request $httpRequest, DataDeletionRequest $request, GdprService $gdpr, AdminAuditService $audit)
    {
        $data = $httpRequest->validate([
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $ok = $gdpr->approveDataDeletion($request, $data['admin_notes'] ?? null);
        $audit->record($httpRequest, 'gdpr.deletion.approve', $request, $ok ? 'success' : 'failed', [
            'request_id' => $request->id,
        ]);

        return redirect()->route('admin.gdpr.deletion-requests.index')
            ->with($ok ? 'status' : 'error', $ok ? '删除请求已批准并完成匿名化。' : '删除请求无法批准。');
    }

    public function reject(Request $httpRequest, DataDeletionRequest $request, GdprService $gdpr, AdminAuditService $audit)
    {
        $data = $httpRequest->validate([
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $ok = $gdpr->rejectDataDeletion($request, $data['admin_notes'] ?? null);
        $audit->record($httpRequest, 'gdpr.deletion.reject', $request, $ok ? 'success' : 'failed', [
            'request_id' => $request->id,
        ]);

        return redirect()->route('admin.gdpr.deletion-requests.index')
            ->with($ok ? 'status' : 'error', $ok ? '删除请求已拒绝。' : '删除请求无法拒绝。');
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
