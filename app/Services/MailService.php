<?php

namespace App\Services;

use App\Jobs\SendEmailJob;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Plugins\Contracts\EmailProviderInterface;
use App\Plugins\Facades\Plugin;
use Illuminate\Support\Str;
use Throwable;

class MailService
{
    private const PROCESSING_TIMEOUT_MINUTES = 15;
    private const SUBJECT_MAX_LENGTH = 255;

    public function __construct(
        private ?SettingsService $settings = null
    ) {
        $this->settings ??= app(SettingsService::class);
    }

    public function send(string $to, string $subject, string $body, array $options = []): bool
    {
        $log = $this->createLog($to, $subject, $body, $options);
        $async = array_key_exists('async', $options)
            ? (bool) $options['async']
            : $this->mailQueueEnabled();

        if ($async) {
            try {
                SendEmailJob::dispatch($log->id);
            } catch (Throwable) {
                $this->markFailed($log->fresh(), 'Email dispatch failed');

                return false;
            }

            return true;
        }

        return $this->sendLog($log);
    }

    public function sendLog(EmailLog $log): bool
    {
        $providerName = (string) ($log->provider ?: $this->settings->get('default_email_provider', 'smtp'));
        $provider = Plugin::get($providerName);

        if (!$provider instanceof EmailProviderInterface) {
            $this->markFailed($log, 'Email provider unavailable');

            return false;
        }

        $sendOptions = array_merge([
            'from_name' => $this->settings->get('mail_from_name', config('mail.from.name')),
            'from_address' => $this->settings->get('mail_from_address', config('mail.from.address')),
        ], $log->payload ?? []);

        try {
            $success = $provider->send($log->to, $log->subject, (string) $log->body, $sendOptions);
            if ($success) {
                $this->markSent($log);
            } else {
                $this->markFailed($log, 'Email provider returned failure');
            }

            return $success;
        } catch (Throwable $exception) {
            $this->markFailed($log, $exception->getMessage());

            return false;
        }
    }

    public function retry(EmailLog $log, bool $async = true): bool
    {
        $this->recoverStaleProcessingLog($log);

        return $this->retryLog($log, $async, function (EmailLog $lockedLog) {
            return $this->refreshTemplateLog($lockedLog);
        }, function (EmailLog $lockedLog) use ($async) {
            if ($async) {
                try {
                    SendEmailJob::dispatch($lockedLog->id);
                } catch (Throwable) {
                    $this->markFailed($lockedLog->fresh(), 'Email retry dispatch failed');

                    return false;
                }

                return true;
            }

            return $this->sendLog($lockedLog->fresh());
        });
    }

    public function recoverStaleProcessing(int $minutes = self::PROCESSING_TIMEOUT_MINUTES): int
    {
        return EmailLog::query()
            ->where('status', 'processing')
            ->where('updated_at', '<=', now()->subMinutes($minutes))
            ->update([
                'status' => 'failed',
                'success' => false,
                'error' => 'Email processing timeout',
            ]);
    }

    private function retryLog(EmailLog $log, bool $async, callable $refresh, callable $dispatch): bool
    {
        return \DB::transaction(function () use ($log, $refresh, $dispatch) {
            $lockedLog = EmailLog::query()->whereKey($log->id)->lockForUpdate()->first();
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

    private function recoverStaleProcessingLog(EmailLog $log): void
    {
        EmailLog::query()
            ->whereKey($log->id)
            ->where('status', 'processing')
            ->where('updated_at', '<=', now()->subMinutes(self::PROCESSING_TIMEOUT_MINUTES))
            ->update([
                'status' => 'failed',
                'success' => false,
                'error' => 'Email processing timeout',
            ]);
    }

    private function refreshTemplateLog(EmailLog $log): ?EmailLog
    {
        if (!$log->template) {
            return $log;
        }

        $template = EmailTemplate::query()
            ->where('name', $log->template)
            ->where('enabled', true)
            ->first();

        if (!$template) {
            $this->markFailed($log, 'Email template unavailable');

            return null;
        }

        $variables = $this->templateVariables($log->payload ?? []);
        $log->update([
            'subject' => $this->normalizeSubject($this->render($template->subject, $variables)),
            'body' => $this->render($template->body, $variables),
            'payload' => array_merge($log->payload ?? [], ['payload' => $variables]),
        ]);

        return $log->fresh();
    }

    private function templateVariables(array $payload): array
    {
        return is_array($payload['payload'] ?? null) ? $payload['payload'] : $payload;
    }

    public function sendTemplate(string $templateName, string $to, array $variables = [], array $options = []): bool
    {
        $template = EmailTemplate::query()
            ->where('name', $templateName)
            ->where('enabled', true)
            ->first();

        if (!$template) {
            $this->createLog($to, $templateName, '', $options + [
                'template' => $templateName,
                'payload' => $variables,
                'status' => 'failed',
                'error' => 'Email template unavailable',
            ]);

            return false;
        }

        return $this->send(
            $to,
            $this->render($template->subject, $variables),
            $this->render($template->body, $variables),
            $options + ['template' => $templateName, 'payload' => $variables]
        );
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

    public function mailQueueEnabled(): bool
    {
        return filter_var($this->settings->get('mail_queue_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    private function createLog(
        string $to,
        string $subject,
        string $body,
        array $options = []
    ): EmailLog {
        $status = (string) ($options['status'] ?? 'pending');
        $success = $status === 'sent' || (bool) ($options['success'] ?? false);
        $providerName = (string) ($options['provider'] ?? $this->settings->get('default_email_provider', 'smtp'));
        $normalizedSubject = $this->normalizeSubject($subject);
        if ($normalizedSubject !== $subject) {
            $options['original_subject'] = $subject;
        }

        return EmailLog::query()->create([
            'to' => $to,
            'subject' => $normalizedSubject,
            'body' => $body,
            'template' => $options['template'] ?? null,
            'provider' => $providerName,
            'status' => $status,
            'success' => $success,
            'payload' => $options,
            'error' => $options['error'] ?? null,
            'attempts' => 0,
            'sent_at' => $success ? now() : null,
        ]);
    }

    private function normalizeSubject(string $subject): string
    {
        if (Str::length($subject) <= self::SUBJECT_MAX_LENGTH) {
            return $subject;
        }

        return Str::substr($subject, 0, self::SUBJECT_MAX_LENGTH);
    }

    private function markSent(EmailLog $log): void
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

    private function markFailed(EmailLog $log, string $error): void
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
