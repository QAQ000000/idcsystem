<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\Order\Models\Host;
use App\Modules\Order\Services\CancelRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use RuntimeException;

class CancelRequestController extends Controller
{
    public function store(Request $request, Host $host, CancelRequestService $cancelRequests): RedirectResponse
    {
        $client = Auth::guard('client')->user();
        abort_unless($client && (int) $host->client_id === (int) $client->id, 403);

        $data = $request->validate([
            'type' => ['required', Rule::in(['immediate', 'end_of_billing_period'])],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $cancelRequests->create($host, $data['type'], $data['reason'] ?? null);
        } catch (RuntimeException $exception) {
            return redirect()->route('client.hosts.show', $host)->withErrors([
                'cancel_request' => $exception->getMessage(),
            ]);
        }

        return redirect()->route('client.hosts.show', $host)->with('status', '取消申请已提交，请等待管理员审核');
    }
}
