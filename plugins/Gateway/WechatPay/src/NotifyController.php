<?php

namespace Plugins\Gateway\WechatPay\src;

use App\Modules\Finance\Services\PaymentService;
use App\Plugins\Facades\Plugin;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class NotifyController
{
    public function handle(Request $request, PaymentService $payment): Response
    {
        $plugin = Plugin::get('wechat_pay');
        $data = $request->all();
        $body = $request->getContent();
        $data['wechatpay_timestamp'] = $request->header('Wechatpay-Timestamp');
        $data['wechatpay_nonce'] = $request->header('Wechatpay-Nonce');
        $data['wechatpay_signature'] = $request->header('Wechatpay-Signature');
        $data['wechatpay_body'] = $body;

        if ($body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $data += $decoded;
            }
        }

        if ($plugin instanceof WechatPayPlugin) {
            $data = $plugin->normalizedCallback($data);
        }

        $handled = $payment->handleCallback('wechat_pay', $data);

        return response($handled ? 'success' : 'fail', $handled ? 200 : 400);
    }
}
