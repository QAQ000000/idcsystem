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
    private const PROCESSING_TIMEOUT_MINUTES = 15;

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
                'payload' => $variables,
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
            try {
                SendSmsJob::dispatch($log->id);
            } catch (Throwable) {
                $this->markFailed($log->fresh(), 'SMS dispatch failed');

                return false;
            }

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
        $this->recoverStaleProcessingLog($log);

        return $this->retryLog($log, function (SmsLog $lockedLog) {
            return $this->refreshTemplateLog($lockedLog);
        }, function (SmsLog $lockedLog) use ($async) {
            if ($async) {
                try {
                    SendSmsJob::dispatch($lockedLog->id);
                } catch (Throwable) {
                    $this->markFailed($lockedLog->fresh(), 'SMS retry dispatch failed');

                    return false;
                }

                return true;
            }

            return $this->sendLog($lockedLog->fresh());
        });
    }

    public function recoverStaleProcessing(int $minutes = self::PROCESSING_TIMEOUT_MINUTES): int
    {
        return SmsLog::query()
            ->where('status', 'processing')
            ->where('updated_at', '<=', now()->subMinutes($minutes))
            ->update([
                'status' => 'failed',
                'success' => false,
                'error' => 'SMS processing timeout',
            ]);
    }

    private function retryLog(SmsLog $log, callable $refresh, callable $dispatch): bool
    {
        return \DB::transaction(function () use ($log, $refresh, $dispatch) {
            $lockedLog = SmsLog::query()->whereKey($log->id)->lockForUpdate()->first();
            if (!$lockedLog || !in_array($lockedLog->status, ['failed', 'pending'], true)) {
                return false;
            }

            $lockedLog = $lockedLog->status === 'failed' ? $refresh($lockedLog) : $lockedLog;
            if (!$lockedLog) {
                return false;
            }

            $lockedLog->update([
                'status' => 'pending',
                'success' => false,
                'error' => null,
                'sent_at' => null,
            ]);

            return $dispatch($lockedLog->fresh());
        });
    }

    private function recoverStaleProcessingLog(SmsLog $log): void
    {
        SmsLog::query()
            ->whereKey($log->id)
            ->where('status', 'processing')
            ->where('updated_at', '<=', now()->subMinutes(self::PROCESSING_TIMEOUT_MINUTES))
            ->update([
                'status' => 'failed',
                'success' => false,
                'error' => 'SMS processing timeout',
            ]);
    }

    private function refreshTemplateLog(SmsLog $log): ?SmsLog
    {
        if (!$log->template) {
            return $log;
        }

        $template = SmsTemplate::query()
            ->where('name', $log->template)
            ->where('enabled', true)
            ->first();

        if (!$template) {
            $this->markFailed($log, 'SMS template unavailable');

            return null;
        }

        $log->update([
            'template_code' => $log->template_code ?: $log->template,
            'content' => $this->render($template->content, $log->payload ?? []),
        ]);

        return $log->fresh();
    }

    public function render(string $content, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $content = str_replace('{{' . $key . '}}', $this->stringifyVariable($value), $content);
        }

        return $content;
    }

    private function stringifyVariable(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? '' : $encoded;
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
        $attempts = $log->status === 'processing' ? $log->attempts : $log->attempts + 1;

        $log->update([
            'status' => 'sent',
            'success' => true,
            'error' => null,
            'attempts' => $attempts,
            'sent_at' => now(),
        ]);
    }

    private function markFailed(SmsLog $log, string $error): void
    {
        $attempts = $log->status === 'processing' ? $log->attempts : $log->attempts + 1;

        $log->update([
            'status' => 'failed',
            'success' => false,
            'error' => $error,
            'attempts' => $attempts,
        ]);
    }
}
