<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\User\Services\AuthService;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokenController extends Controller
{
    public function index(Request $request)
    {
        $client = $request->user('client');
        $tokens = $client->tokens()->latest()->get();
        $abilities = config('sanctum.abilities', []);

        return view('theme::api-tokens.index', compact('tokens', 'abilities'));
    }

    public function store(Request $request, AuthService $auth)
    {
        $abilities = array_keys(config('sanctum.abilities', []));
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', 'in:' . implode(',', $abilities)],
            'ip_whitelist' => ['nullable', 'string', 'max:1000'],
        ]);

        $credential = $auth->createTokenCredential(
            $request->user('client'),
            $data['name'],
            array_values($data['abilities']),
            $this->parseIpWhitelist($data['ip_whitelist'] ?? '')
        );

        return redirect()
            ->route('client.api-tokens.index')
            ->with('status', 'Token 已创建')
            ->with('plain_text_token', $credential['token'])
            ->with('api_secret', $credential['api_secret']);
    }

    public function destroy(Request $request, PersonalAccessToken $token)
    {
        abort_unless((int) $token->tokenable_id === (int) $request->user('client')->id
            && $token->tokenable_type === $request->user('client')::class, 404);

        $token->delete();

        return redirect()->route('client.api-tokens.index')->with('status', 'Token 已撤销');
    }

    private function parseIpWhitelist(string $input): array
    {
        $items = preg_split('/[\s,]+/', $input) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $items))));
    }
}
