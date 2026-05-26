@extends('layouts.admin')

@section('title', 'API 文档')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">API 文档</h1>
            <p class="mt-1 text-sm text-slate-500">第三方接入使用 Sanctum Bearer Token 调用 REST API。</p>
        </div>
        <div class="flex gap-2">
            <a class="rounded bg-slate-900 px-4 py-2 text-sm text-white" href="{{ url('/docs/api') }}">打开 OpenAPI</a>
            <a class="rounded border px-4 py-2 text-sm" href="{{ url('/docs/api.json') }}">下载 JSON</a>
        </div>
    </div>

    <section class="grid gap-6 lg:grid-cols-2">
        <div class="rounded bg-white p-5 shadow-sm">
            <h2 class="mb-3 text-lg font-semibold">认证</h2>
            <pre class="overflow-x-auto rounded bg-slate-950 p-4 text-xs text-slate-100">POST /api/auth/login
{
  "email": "client@example.com",
  "password": "client123456",
  "device_name": "integration"
}</pre>
            <p class="mt-3 text-sm text-slate-600">后续请求添加请求头：Authorization: Bearer &lt;token&gt;</p>
        </div>

        <div class="rounded bg-white p-5 shadow-sm">
            <h2 class="mb-3 text-lg font-semibold">常用接口</h2>
            <ul class="space-y-2 text-sm text-slate-700">
                <li><code>GET /api/products</code> 产品列表</li>
                <li><code>GET /api/hosts</code> 当前客户服务</li>
                <li><code>GET /api/invoices</code> 账单列表</li>
                <li><code>POST /api/invoices/{id}/pay-with-credit</code> 余额支付</li>
                <li><code>GET /api/payment/gateways</code> 可用支付网关</li>
            </ul>
        </div>
    </section>

    <section class="mt-6 rounded bg-white p-5 shadow-sm">
        <h2 class="mb-3 text-lg font-semibold">响应格式</h2>
        <pre class="overflow-x-auto rounded bg-slate-950 p-4 text-xs text-slate-100">{
  "success": true,
  "data": {}
}</pre>
        <pre class="mt-3 overflow-x-auto rounded bg-slate-950 p-4 text-xs text-slate-100">{
  "success": false,
  "message": "错误描述"
}</pre>
    </section>
@endsection
