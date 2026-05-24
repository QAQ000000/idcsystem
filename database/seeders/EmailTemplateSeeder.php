<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use Illuminate\Database\Seeder;

class EmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $template) {
            EmailTemplate::query()->updateOrCreate(
                ['name' => $template['name']],
                $template
            );
        }
    }

    private function templates(): array
    {
        return [
            [
                'name' => 'invoice_created',
                'subject' => '账单 {{invoice_number}} 已生成',
                'body' => '您好 {{client_name}}，您的账单 {{invoice_number}} 已生成，应付金额 {{amount}}。',
                'enabled' => true,
            ],
            [
                'name' => 'invoice_paid',
                'subject' => '账单 {{invoice_number}} 已支付',
                'body' => '您好 {{client_name}}，您的账单 {{invoice_number}} 已支付成功，支付金额 {{amount}}。',
                'enabled' => true,
            ],
            [
                'name' => 'ticket_replied',
                'subject' => '工单 {{ticket_number}} 有新回复',
                'body' => '您好 {{client_name}}，您的工单 {{ticket_number}} 有新回复：{{reply_message}}',
                'enabled' => true,
            ],
        ];
    }
}
