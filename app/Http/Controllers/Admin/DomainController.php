<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Product\Models\Domain;
use App\Modules\User\Models\Client;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DomainController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'status' => $this->queryString($request, 'status'),
            'client_id' => $this->queryInteger($request, 'client_id'),
        ];

        $domains = Domain::query()
            ->with('client')
            ->when($filters['status'], fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['client_id'], fn ($query, int $clientId) => $query->where('client_id', $clientId))
            ->orderBy('expiry_date')
            ->paginate(20)
            ->withQueryString();

        return view('admin.domains.index', [
            'domains' => $domains,
            'clients' => Client::withTrashed()->orderBy('username')->get(['id', 'username', 'email', 'deleted_at']),
            'statuses' => ['Active', 'Pending', 'Expired', 'Cancelled', 'Transferred'],
            'filters' => $filters,
        ]);
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

    private function queryInteger(Request $request, string $key): ?int
    {
        $value = $this->queryString($request, $key);

        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }
}
