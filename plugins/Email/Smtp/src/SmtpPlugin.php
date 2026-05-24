<?php

namespace Plugins\Email\Smtp\src;

use App\Plugins\Contracts\EmailProviderInterface;
use App\Plugins\Core\AbstractPlugin;
use Illuminate\Support\Facades\Mail;

class SmtpPlugin extends AbstractPlugin implements EmailProviderInterface
{
    public function getName(): string
    {
        return 'smtp';
    }

    public function getTitle(): string
    {
        return 'SMTP 邮件';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getType(): string
    {
        return 'email';
    }

    public function getDescription(): string
    {
        return '内置 SMTP 邮件发送插件。';
    }

    public function send(string $to, string $subject, string $body, array $options = []): bool
    {
        Mail::html($body, function ($message) use ($to, $subject, $options) {
            $message->to($to)->subject($subject);

            if (!empty($options['from_address'])) {
                $message->from($options['from_address'], $options['from_name'] ?? null);
            }
        });

        return true;
    }
}
