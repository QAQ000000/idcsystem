<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\DataExportRequest;
use App\Modules\User\Models\Client;
use App\Services\GdprService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrivacyController extends Controller
{
    public function index()
    {
        $client = Auth::guard('client')->user();

        return view('theme::account.privacy', [
            'client' => $client,
            'exportRequests' => $client->dataExportRequests()->latest()->limit(10)->get(),
            'deletionRequests' => $client->dataDeletionRequests()->latest()->limit(10)->get(),
            'consents' => $client->privacyPolicyConsents()->latest('consented_at')->limit(10)->get(),
            'policyVersion' => config('app.privacy_policy_version', '1.0'),
        ]);
    }

    public function exportData(GdprService $gdpr)
    {
        $gdpr->requestDataExport(Auth::guard('client')->user());

        return redirect()->route('client.account.privacy')->with('status', '数据导出请求已创建，请稍后下载。');
    }

    public function downloadExport(DataExportRequest $request): StreamedResponse
    {
        $client = Auth::guard('client')->user();
        abort_unless((int) $request->client_id === (int) $client->id, 403);
        abort_unless($request->status === 'completed' && $request->file_path, 404);
        abort_unless(Storage::disk('local')->exists($request->file_path), 404);

        return Storage::disk('local')->download($request->file_path, 'client-data-export-' . $request->id . '.json');
    }

    public function deleteAccount(Request $request, GdprService $gdpr)
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $gdpr->requestDataDeletion(Auth::guard('client')->user(), $data['reason'] ?? null);

        return redirect()->route('client.account.privacy')->with('status', '账户删除请求已提交，等待管理员审批。');
    }
}
