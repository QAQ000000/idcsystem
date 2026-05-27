<?php

namespace App\Http\Controllers\Api;

use App\Modules\User\Models\Client;
use App\Modules\User\Services\AuthService;
use App\Modules\User\Services\ClientCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AuthController extends ApiController
{
    /**
     * 客户登录并签发 API Token。
     *
     * @response 200 {"success":true,"data":{"token":"1|plain-text-token","client":{"id":1,"username":"demo","email":"demo@example.com"}}}
     */
    public function login(Request $request, AuthService $auth): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $client = $auth->login($data['email'], $data['password']);
        if (!$client) {
            return $this->error($auth->lastLoginFailureMessage() ?: '邮箱或密码错误，或账号不可用。', 422);
        }

        $auth->recordLogin($client);

        $credential = $auth->createTokenCredential($client, $data['device_name'] ?? 'api');

        return $this->success([
            'token' => $credential['token'],
            'api_secret' => $credential['api_secret'],
            'client' => $this->clientPayload($client->fresh()),
        ]);
    }

    /**
     * 注册客户并返回 API Token。
     *
     * @response 201 {"success":true,"data":{"token":"1|plain-text-token","client":{"id":1,"username":"demo","email":"demo@example.com"}}}
     */
    public function register(Request $request, AuthService $auth): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:50', 'unique:clients,username'],
            'email' => ['required', 'email', 'max:100', 'unique:clients,email'],
            'password' => ['required', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:50'],
            'currency_id' => ['nullable', 'integer', Rule::exists('currencies', 'id')],
            'device_name' => ['nullable', 'string', 'max:100'],
            'privacy_policy' => ['accepted'],
        ]);

        $password = $data['password'];
        $deviceName = $data['device_name'] ?? 'api';
        unset($data['device_name']);
        unset($data['privacy_policy']);
        $data['privacy_ip'] = $request->ip();

        $client = $auth->register($data);
        // API 注册完成后签发 Token，保持第三方接入流程闭环。
        if (!$client->isActive() && Hash::check($password, $client->password)) {
            $client->update(['status' => 1]);
        }

        $credential = $auth->createTokenCredential($client->fresh(), $deviceName);

        return $this->success([
            'token' => $credential['token'],
            'api_secret' => $credential['api_secret'],
            'client' => $this->clientPayload($client->fresh()),
        ], 201);
    }

    /**
     * 注销当前 Token。
     *
     * @response 200 {"success":true,"data":{"logged_out":true}}
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        if ($token) {
            $token->delete();
        }

        return $this->success(['logged_out' => true]);
    }

    /**
     * 获取当前客户资料。
     *
     * @response 200 {"success":true,"data":{"client":{"id":1,"username":"demo","email":"demo@example.com"}}}
     */
    public function me(Request $request): JsonResponse
    {
        return $this->success([
            'client' => $this->clientPayload($request->user()),
        ]);
    }

    private function clientPayload(Client $client): array
    {
        return [
            'id' => $client->id,
            'username' => $client->username,
            'email' => $client->email,
            'status' => $client->status,
            'phone' => $client->phone,
            'company_name' => $client->company_name,
        ] + app(ClientCacheService::class)->getCreditSummary((int) $client->id);
    }
}
