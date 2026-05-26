<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
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

    public function store(Request $request)
    {
        $abilities = array_keys(config('sanctum.abilities', []));
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', 'in:' . implode(',', $abilities)],
        ]);

        $token = $request->user('client')->createToken($data['name'], array_values($data['abilities']));

        return redirect()
            ->route('client.api-tokens.index')
            ->with('status', 'Token 已创建')
            ->with('plain_text_token', $token->plainTextToken);
    }

    public function destroy(Request $request, PersonalAccessToken $token)
    {
        abort_unless((int) $token->tokenable_id === (int) $request->user('client')->id
            && $token->tokenable_type === $request->user('client')::class, 404);

        $token->delete();

        return redirect()->route('client.api-tokens.index')->with('status', 'Token 已撤销');
    }
}
