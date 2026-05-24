<?php

namespace App\Services;

use App\Jobs\SendEmailJob;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Plugins\Contracts\EmailProviderInterface;
use App\Plugins\Facades\Plugin;
use Throwable;

class MailService
{
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
            SendEmailJob::dispatch($log->id);

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
        $log->update([
            'status' => 'pending',
            'success' => false,
            'error' => null,
            'sent_at' => null,
        ]);

        if ($async) {
            SendEmailJob::dispatch($log->id);

            return true;
        }

        return $this->sendLog($log->fresh());
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
            $content = str_replace('{{' . $key . '}}', (string) $value, $content);
        }

        return $content;
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

        return EmailLog::query()->create([
            'to' => $to,
            'subject' => $subject,
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

    private function markSent(EmailLog $log): void
    {
        $log->update([
            'status' => 'sent',
            'success' => true,
            'error' => null,
            'attempts' => $log->attempts + 1,
            'sent_at' => now(),
        ]);
    }

    private function markFailed(EmailLog $log, string $error): void
    {
        $log->update([
            'status' => 'failed',
            'success' => false,
            'error' => $error,
            'attempts' => $log->attempts + 1,
        ]);
    }
}
