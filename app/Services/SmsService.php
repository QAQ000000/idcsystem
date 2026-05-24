<?php

namespace App\Services;

use App\Jobs\SendSmsJob;
use App\Models\SmsLog;
use App\Models\SmsTemplate;
use App\Plugins\Contracts\SmsProviderInterface;
use App\Plugins\Facades\Plugin;
use Throwable;

class SmsService
{
    public function __construct(
        private ?SettingsService $settings = null
    ) {
        $this->settings ??= app(SettingsService::class);
    }

    public function send(string $phone, string $templateName, array $variables = [], array $options = []): bool
    {
        $template = SmsTemplate::query()
            ->where('name', $templateName)
            ->where('enabled', true)
            ->first();

        if (!$template) {
            $this->createLog($phone, $templateName, '', $options + [
                'template' => $templateName,
                'status' => 'failed',
                'error' => 'SMS template unavailable',
            ]);

            return false;
        }

        $content = $this->render($template->content, $variables);
        $log = $this->createLog($phone, $content, $content, $options + [
            'template' => $templateName,
            'template_code' => $options['template_code'] ?? $templateName,
            'payload' => $variables,
        ]);

        $async = array_key_exists('async', $options)
            ? (bool) $options['async']
            : $this->smsQueueEnabled();

        if ($async) {
            SendSmsJob::dispatch($log->id);

            return true;
        }

        return $this->sendLog($log);
    }

    public function sendLog(SmsLog $log): bool
    {
        $providerName = (string) ($log->provider ?: $this->settings->get('default_sms_provider', 'aliyun'));
        $provider = Plugin::get($providerName);

        if (!$provider instanceof SmsProviderInterface) {
            $this->markFailed($log, 'SMS provider unavailable');

            return false;
        }

        $sendOptions = [
            'sign_name' => $this->settings->get('sms_signature', ''),
            'provider_config' => $provider->getConfig(),
        ];

        try {
            $success = $provider->send($log->phone, (string) ($log->template_code ?: $log->template), array_merge($sendOptions, $log->payload ?? []));
            if ($success) {
                $this->markSent($log);
            } else {
                $this->markFailed($log, 'SMS provider returned failure');
            }

            return $success;
        } catch (Throwable $exception) {
            $this->markFailed($log, $exception->getMessage());

            return false;
        }
    }

    public function retry(SmsLog $log, bool $async = true): bool
    {
        $log->update([
            'status' => 'pending',
            'success' => false,
            'error' => null,
            'sent_at' => null,
        ]);

        if ($async) {
            SendSmsJob::dispatch($log->id);

            return true;
        }

        return $this->sendLog($log->fresh());
    }

    public function render(string $content, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $content = str_replace('{{' . $key . '}}', (string) $value, $content);
        }

        return $content;
    }

    public function smsQueueEnabled(): bool
    {
        return filter_var($this->settings->get('sms_queue_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    private function createLog(string $phone, string $subject, string $content, array $options = []): SmsLog
    {
        $status = (string) ($options['status'] ?? 'pending');
        $success = $status === 'sent' || (bool) ($options['success'] ?? false);
        $providerName = (string) ($options['provider'] ?? $this->settings->get('default_sms_provider', 'aliyun'));

        return SmsLog::query()->create([
            'phone' => $phone,
            'template' => $options['template'] ?? null,
            'template_code' => $options['template_code'] ?? null,
            'content' => $content,
            'provider' => $providerName,
            'status' => $status,
            'success' => $success,
            'payload' => $options['payload'] ?? [],
            'error' => $options['error'] ?? null,
            'attempts' => 0,
            'sent_at' => $success ? now() : null,
        ]);
    }

    private function markSent(SmsLog $log): void
    {
        $log->update([
            'status' => 'sent',
            'success' => true,
            'error' => null,
            'attempts' => $log->attempts + 1,
            'sent_at' => now(),
        ]);
    }

    private function markFailed(SmsLog $log, string $error): void
    {
        $log->update([
            'status' => 'failed',
            'success' => false,
            'error' => $error,
            'attempts' => $log->attempts + 1,
        ]);
    }
}
