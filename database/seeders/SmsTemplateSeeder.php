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
            [
                'name' => 'password_changed',
                'content' => '您好 {{client_name}}，您的账户密码已修改。如非本人操作，请联系管理员。',
                'enabled' => true,
            ],
            [
                'name' => 'host_renewal_invoice_created',
                'content' => '您好 {{client_name}}，您的 {{product_name}} 续费账单 {{invoice_number}} 已生成，金额 {{amount}}。',
                'enabled' => true,
            ],
            [
                'name' => 'host_due_reminder',
                'content' => '您好 {{client_name}}，您的 {{product_name}} 将于 {{due_date}} 到期，请及时续费。',
                'enabled' => true,
            ],
            [
                'name' => 'host_upgrade_completed',
                'content' => '您好 {{client_name}}，您的 {{product_name}} 升级/降配已完成。',
                'enabled' => true,
            ],
        ];
    }
}
