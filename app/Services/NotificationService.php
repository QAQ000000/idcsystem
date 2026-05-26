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

    public function notifyClient(Client $client, string $event, array $variables = []): array
    {
        $result = [
            'mail' => null,
            'sms' => null,
            'errors' => [],
        ];

        if ($client->trashed() || (!$client->isActive() && $event !== 'email_verification')) {
            $result['errors']['client'] = '客户账号未启用或已删除，跳过通知。';

            return $result;
        }

        if ($this->enabled($event, 'mail') && !empty($client->email)) {
            try {
                $result['mail'] = $this->mail->sendTemplate($event, (string) $client->email, $variables);
            } catch (Throwable $exception) {
                $result['mail'] = false;
                $result['errors']['mail'] = $exception->getMessage();
                report($exception);
            }
        }

        if ($this->enabled($event, 'sms') && !empty($client->phone)) {
            try {
                $result['sms'] = $this->sms->send((string) $client->phone, $event, $variables);
            } catch (Throwable $exception) {
                $result['sms'] = false;
                $result['errors']['sms'] = $exception->getMessage();
                report($exception);
            }
        }

        return $result;
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
            'invoice_receipt_submitted' => [
                'label' => '发票申请提交',
                'variables' => ['client_name', 'invoice_number', 'receipt_title'],
            ],
            'invoice_receipt_issued' => [
                'label' => '发票已开具',
                'variables' => ['client_name', 'invoice_number', 'receipt_title'],
            ],
            'ticket_replied' => [
                'label' => '工单回复',
                'variables' => ['client_name', 'ticket_number', 'reply_message'],
            ],
            'password_changed' => [
                'label' => '密码修改',
                'variables' => ['client_name'],
            ],
            'email_verification' => [
                'label' => '邮件验证',
                'variables' => ['client_name', 'verify_url'],
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
            'host_cancel_requested' => [
                'label' => '取消申请提交',
                'variables' => ['client_name', 'product_name', 'host_id', 'cancel_type', 'cancel_reason'],
            ],
            'host_cancel_approved' => [
                'label' => '取消申请已批准',
                'variables' => ['client_name', 'product_name', 'host_id', 'cancel_type', 'admin_notes'],
            ],
            'host_cancel_rejected' => [
                'label' => '取消申请已拒绝',
                'variables' => ['client_name', 'product_name', 'host_id', 'admin_notes'],
            ],
            'host_cancel_completed' => [
                'label' => '服务取消已执行',
                'variables' => ['client_name', 'product_name', 'host_id'],
            ],
            'custom_email' => [
                'label' => '自定义邮件',
                'variables' => ['client_name', 'subject', 'body'],
            ],
        ];
    }
}
