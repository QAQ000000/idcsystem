<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoginAttempt;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LoginAttemptController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'email' => ['nullable', 'string', 'max:100'],
            'ip' => ['nullable', 'string', 'max:45'],
            'status' => ['nullable', Rule::in(['success', 'failed'])],
        ]);

        $attempts = LoginAttempt::query()
            ->when(trim((string) ($filters['email'] ?? '')) !== '', function ($query) use ($filters): void {
                $query->where('email', 'like', '%' . trim((string) $filters['email']) . '%');
            })
            ->when(trim((string) ($filters['ip'] ?? '')) !== '', function ($query) use ($filters): void {
                $query->where('ip', 'like', '%' . trim((string) $filters['ip']) . '%');
            })
            ->when(($filters['status'] ?? null) !== null, function ($query) use ($filters): void {
                $query->where('status', $filters['status']);
            })
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.login-attempts.index', [
            'attempts' => $attempts,
            'filters' => $filters,
        ]);
    }
}
