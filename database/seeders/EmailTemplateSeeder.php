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
            [
                'name' => 'password_changed',
                'subject' => '您的账户密码已修改',
                'body' => '您好 {{client_name}}，您的账户密码已修改。如非本人操作，请立即联系管理员。',
                'enabled' => true,
            ],
            [
                'name' => 'host_renewal_invoice_created',
                'subject' => '{{product_name}} 续费账单已生成',
                'body' => '您好 {{client_name}}，您的 {{product_name}} 续费账单 {{invoice_number}} 已生成，金额 {{amount}}。',
                'enabled' => true,
            ],
            [
                'name' => 'host_due_reminder',
                'subject' => '{{product_name}} 即将到期',
                'body' => '您好 {{client_name}}，您的 {{product_name}} 将于 {{due_date}} 到期，请及时续费。',
                'enabled' => true,
            ],
            [
                'name' => 'host_upgrade_completed',
                'subject' => '{{product_name}} 调整已完成',
                'body' => '您好 {{client_name}}，您的 {{product_name}} 升级/降配已完成。',
                'enabled' => true,
            ],
        ];
    }
}
