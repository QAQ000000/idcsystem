<?php

namespace App\Http\Controllers\Api;

use App\Models\Plugin;
use Illuminate\Http\JsonResponse;

class PaymentController extends ApiController
{
    public function gateways(): JsonResponse
    {
        $gateways = Plugin::query()
            ->where('type', 'gateway')
            ->where('status', 1)
            ->orderBy('title')
            ->get(['name', 'title', 'type'])
            ->map(fn (Plugin $plugin) => [
                'name' => $plugin->name,
                'title' => $plugin->title,
                'type' => $plugin->type,
            ])
            ->values()
            ->all();

        return $this->success($gateways);
    }
}
