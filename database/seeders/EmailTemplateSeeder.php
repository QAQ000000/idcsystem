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
                'name' => 'email_verification',
                'subject' => '请验证您的邮箱',
                'body' => '您好 {{client_name}}，请点击以下链接完成邮箱验证：{{verify_url}}。该链接 24 小时内有效。',
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
                'body' => '您好 {{client_name}}，您的 {{product_name}} 将在 {{days}} 天后（{{due_date}}）到期，请及时续费。',
                'enabled' => true,
            ],
            [
                'name' => 'domain_expiry_reminder',
                'subject' => '域名 {{domain}} 即将到期',
                'body' => '您好 {{client_name}}，您的域名 {{domain}} 将在 {{days}} 天后（{{expiry_date}}）到期，请及时续费。',
                'enabled' => true,
            ],
            [
                'name' => 'host_upgrade_completed',
                'subject' => '{{product_name}} 调整已完成',
                'body' => '您好 {{client_name}}，您的 {{product_name}} 升级/降配已完成。',
                'enabled' => true,
            ],
            [
                'name' => 'usage_alert',
                'subject' => '{{product_name}} 用量告警',
                'body' => '您好 {{client_name}}，您的服务 {{product_name}} 的 {{metric}} 使用率已达到 {{current_value}}%，超过设定阈值 {{threshold}}%。',
                'enabled' => true,
            ],
        ];
    }
}
