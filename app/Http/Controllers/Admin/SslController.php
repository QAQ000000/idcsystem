<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Product\Models\SslCertificate;
use App\Modules\Product\Services\SslService;
use Illuminate\Http\Request;

class SslController extends Controller
{
    public function index(Request $request)
    {
        $filters = [
            'status' => $this->queryString($request, 'status'),
            'type' => $this->queryString($request, 'type'),
        ];

        $certificates = SslCertificate::query()
            ->with(['client', 'host'])
            ->when($filters['status'], fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['type'], fn ($query, string $type) => $query->where('type', $type))
            ->orderByRaw('expiry_date is null, expiry_date asc')
            ->paginate(20)
            ->withQueryString();

        return view('admin.ssl.index', [
            'certificates' => $certificates,
            'filters' => $filters,
            'statuses' => ['Active', 'Pending', 'Expired', 'Cancelled'],
            'types' => ['paid', 'letsencrypt'],
        ]);
    }

    public function issue(SslCertificate $certificate, SslService $ssl)
    {
        try {
            $ssl->issueLetsEncrypt($certificate);
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('admin.ssl.index')->with('status', '证书已签发');
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
