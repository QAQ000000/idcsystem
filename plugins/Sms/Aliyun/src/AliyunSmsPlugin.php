<?php

namespace Plugins\Sms\Aliyun\src;

use App\Plugins\Contracts\SmsProviderInterface;
use App\Plugins\Core\AbstractPlugin;
use Illuminate\Support\Facades\Http;

class AliyunSmsPlugin extends AbstractPlugin implements SmsProviderInterface
{
    public function getName(): string
    {
        return 'aliyun';
    }

    public function getTitle(): string
    {
        return '阿里云短信';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getType(): string
    {
        return 'sms';
    }

    public function getDescription(): string
    {
        return '内置阿里云短信发送插件。';
    }

    public function send(string $phone, string $templateCode, array $params = []): bool
    {
        $config = $this->getConfig();

        if (($config['mock'] ?? true) || empty($config['endpoint'])) {
            return $phone !== '' && $templateCode !== '';
        }

        $response = Http::timeout(10)->post($config['endpoint'], [
            'access_key_id' => $config['access_key_id'] ?? '',
            'access_key_secret' => $config['access_key_secret'] ?? '',
            'sign_name' => $config['sign_name'] ?? '',
            'phone' => $phone,
            'template_code' => $templateCode,
            'params' => $params,
        ]);

        return $response->successful();
    }
}
