<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\User\Models\Affiliate;
use App\Modules\User\Models\AffiliateCommission;
use App\Modules\User\Services\AffiliateService;
use App\Services\AdminAuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AffiliateController extends Controller
{
    public function index(Request $request)
    {
        $status = $this->queryString($request, 'status');
        $keyword = $this->queryString($request, 'keyword');

        $affiliates = Affiliate::query()
            ->with(['client', 'commissions'])
            ->when($keyword, function ($query, string $keyword) {
                $query->where('code', 'like', "%{$keyword}%")
                    ->orWhereHas('client', function ($clientQuery) use ($keyword) {
                        $clientQuery->where('username', 'like', "%{$keyword}%")
                            ->orWhere('email', 'like', "%{$keyword}%");
                    });
            })
            ->when($status, fn ($query, string $status) => $query->where('status', $status))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $commissions = AffiliateCommission::query()
            ->with(['affiliate.client', 'referredClient', 'invoice'])
            ->when($this->queryString($request, 'commission_status'), fn ($query, string $commissionStatus) => $query->where('status', $commissionStatus))
            ->latest()
            ->paginate(20, ['*'], 'commissions_page')
            ->withQueryString();

        return view('admin.affiliates.index', [
            'affiliates' => $affiliates,
            'commissions' => $commissions,
            'filters' => [
                'keyword' => $keyword,
                'status' => $status,
                'commission_status' => $this->queryString($request, 'commission_status'),
            ],
        ]);
    }

    public function approve(Request $request, AffiliateCommission $commission, AffiliateService $affiliates, AdminAuditService $audit)
    {
        $success = $affiliates->approve($commission);
        $audit->record($request, 'affiliate.commission.approve', $commission, $success ? 'success' : 'failed', [
            'commission_id' => $commission->id,
            'amount' => (float) $commission->amount,
            'status' => $commission->fresh()->status,
        ], $success ? null : '佣金状态不允许审核');

        return back()->with($success ? 'status' : 'error', $success ? '佣金已审核' : '佣金状态不允许审核');
    }

    public function payout(Request $request, Affiliate $affiliate, AffiliateService $affiliates, AdminAuditService $audit)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
        ]);

        $success = $affiliates->withdraw($affiliate, (float) $data['amount']);
        $audit->record($request, 'affiliate.payout', $affiliate, $success ? 'success' : 'failed', [
            'affiliate_id' => $affiliate->id,
            'amount' => (float) $data['amount'],
            'balance' => (float) $affiliate->fresh()->balance,
        ], $success ? null : '可发放佣金不足或客户状态不允许发放');

        return back()->with($success ? 'status' : 'error', $success ? '佣金已发放到账户余额' : '可发放佣金不足或客户状态不允许发放');
    }

    public function update(Request $request, Affiliate $affiliate, AdminAuditService $audit)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $affiliate->update($data);
        $audit->record($request, 'affiliate.update', $affiliate, 'success', $data);

        return back()->with('status', '推介账户已更新');
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
