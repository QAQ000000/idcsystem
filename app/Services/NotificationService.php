<?php

namespace App\Services;

use App\Modules\User\Models\Client;
use Throwable;

class NotificationService
{
    public function __construct(
        private ?SettingsService $settings = null,
        private ?MailService $mail = null,
        private ?SmsService $sms = null
    ) {
        $this->settings ??= app(SettingsService::class);
        $this->mail ??= app(MailService::class);
        $this->sms ??= app(SmsService::class);
    }

    public function notifyClient(Client $client, string $event, array $variables = []): void
    {
        if ($this->enabled($event, 'mail') && !empty($client->email)) {
            try {
                $this->mail->sendTemplate($event, (string) $client->email, $variables);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        if ($this->enabled($event, 'sms') && !empty($client->phone)) {
            try {
                $this->sms->send((string) $client->phone, $event, $variables);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    public function enabled(string $event, string $channel): bool
    {
        return filter_var($this->settings->get("notify_{$event}_{$channel}", true), FILTER_VALIDATE_BOOLEAN);
    }

    public function deliveryMode(string $channel): string
    {
        return $channel === 'mail'
            ? ($this->settings->get('mail_queue_enabled', false) ? 'async' : 'sync')
            : ($this->settings->get('sms_queue_enabled', false) ? 'async' : 'sync');
    }

    public static function events(): array
    {
        return [
            'invoice_created' => [
                'label' => '账单生成',
                'variables' => ['client_name', 'invoice_number', 'amount'],
            ],
            'invoice_paid' => [
                'label' => '支付成功',
                'variables' => ['client_name', 'invoice_number', 'amount'],
            ],
            'ticket_replied' => [
                'label' => '工单回复',
                'variables' => ['client_name', 'ticket_number', 'reply_message'],
            ],
            'password_changed' => [
                'label' => '密码修改',
                'variables' => ['client_name'],
            ],
            'host_renewal_invoice_created' => [
                'label' => '续费账单生成',
                'variables' => ['client_name', 'product_name', 'invoice_number', 'amount'],
            ],
            'host_due_reminder' => [
                'label' => '服务到期提醒',
                'variables' => ['client_name', 'product_name', 'due_date'],
            ],
            'host_upgrade_completed' => [
                'label' => '升级/降配完成',
                'variables' => ['client_name', 'product_name'],
            ],
        ];
    }
}
