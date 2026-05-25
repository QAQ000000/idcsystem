<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Contract;
use App\Modules\Finance\Services\ContractService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ContractController extends Controller
{
    public function index()
    {
        $client = Auth::guard('client')->user();

        return view('theme::contracts.index', [
            'contracts' => Contract::query()
                ->with('order')
                ->where('client_id', $client->id)
                ->latest()
                ->paginate(20),
        ]);
    }

    public function show(Contract $contract)
    {
        $this->authorizeContract($contract);
        $contract->load('order');

        return view('theme::contracts.show', compact('contract'));
    }

    public function sign(Request $request, Contract $contract, ContractService $contracts)
    {
        $this->authorizeContract($contract);

        if (!$contracts->sign($contract, (string) $request->ip())) {
            return redirect()->route('client.contracts.show', $contract)->with('error', '当前合同状态不允许签署');
        }

        return redirect()->route('client.contracts.show', $contract)->with('status', '合同已签署');
    }

    private function authorizeContract(Contract $contract): void
    {
        $client = Auth::guard('client')->user();

        abort_unless($client && (int) $contract->client_id === (int) $client->id, 403);
    }
}
