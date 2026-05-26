<?php

namespace App\Jobs;

use App\Models\EmailCampaignRecipient;
use App\Services\EmailCampaignService;
use App\Services\MailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendCampaignEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public int $recipientId)
    {
        $this->onQueue('notifications');
    }

    public function handle(MailService $mail, EmailCampaignService $campaigns): void
    {
        $recipient = EmailCampaignRecipient::query()
            ->with(['campaign', 'client'])
            ->whereKey($this->recipientId)
            ->first();

        if (!$recipient || $recipient->status !== 'pending' || !$recipient->client || !$recipient->campaign) {
            return;
        }

        $sent = $mail->send(
            (string) $recipient->client->email,
            $recipient->campaign->subject,
            $campaigns->renderForRecipient($recipient),
            [
                'async' => false,
                'template' => 'email_campaign',
                'payload' => [
                    'campaign_id' => $recipient->campaign_id,
                    'recipient_id' => $recipient->id,
                    'client_id' => $recipient->client_id,
                ],
            ]
        );

        if (!$sent) {
            $campaigns->markRecipientFailed($recipient);

            throw new \RuntimeException('Campaign email failed for recipient #' . $recipient->id);
        }

        $campaigns->markRecipientSent($recipient);
    }

    public function failed(Throwable $exception): void
    {
        $recipient = EmailCampaignRecipient::query()->find($this->recipientId);
        if ($recipient && $recipient->status === 'pending') {
            app(EmailCampaignService::class)->markRecipientFailed($recipient);
        }
    }
}
