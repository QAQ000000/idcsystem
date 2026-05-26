<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Order\Models\CancelRequest;
use App\Modules\Order\Services\CancelRequestService;
use App\Services\AdminAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CancelRequestController extends Controller
{
    public function index(Request $request): View
    {
        $status = $this->queryString($request, 'status');
        $requests = CancelRequest::query()
            ->with(['client', 'host.product'])
            ->when($status, fn ($query, string $value) => $query->where('status', $value))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.cancel-requests.index', [
            'requests' => $requests,
            'status' => $status,
            'statuses' => ['pending', 'approved', 'rejected', 'completed'],
        ]);
    }

    public function approve(
        Request $request,
        CancelRequest $cancelRequest,
        CancelRequestService $cancelRequests,
        AdminAuditService $audit
    ): RedirectResponse {
        $data = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $success = $cancelRequests->approve($cancelRequest, $data['admin_notes'] ?? null);
        $audit->record($request, 'cancel_request.approve', $cancelRequest, $success ? 'success' : 'failed', [
            'admin_notes' => $data['admin_notes'] ?? null,
        ], $success ? null : '取消申请审批失败');

        return back()->with($success ? 'status' : 'error', $success ? '取消申请已批准' : '取消申请审批失败');
    }

    public function reject(
        Request $request,
        CancelRequest $cancelRequest,
        CancelRequestService $cancelRequests,
        AdminAuditService $audit
    ): RedirectResponse {
        $data = $request->validate([
            'admin_notes' => ['required', 'string', 'max:2000'],
        ]);

        $success = $cancelRequests->reject($cancelRequest, $data['admin_notes']);
        $audit->record($request, 'cancel_request.reject', $cancelRequest, $success ? 'success' : 'failed', [
            'admin_notes' => $data['admin_notes'],
        ], $success ? null : '取消申请拒绝失败');

        return back()->with($success ? 'status' : 'error', $success ? '取消申请已拒绝' : '取消申请拒绝失败');
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
