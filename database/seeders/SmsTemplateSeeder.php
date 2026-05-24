<?php

namespace Database\Seeders;

use App\Models\SmsTemplate;
use Illuminate\Database\Seeder;

class SmsTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $template) {
            SmsTemplate::query()->updateOrCreate(
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
                'content' => '您好 {{client_name}}，您的账单 {{invoice_number}} 已生成，应付金额 {{amount}}。',
                'enabled' => true,
            ],
            [
                'name' => 'invoice_paid',
                'content' => '您好 {{client_name}}，您的账单 {{invoice_number}} 已支付成功，金额 {{amount}}。',
                'enabled' => true,
            ],
            [
                'name' => 'ticket_replied',
                'content' => '您好 {{client_name}}，您的工单 {{ticket_number}} 有新回复。',
                'enabled' => true,
            ],
        ];
    }
}
