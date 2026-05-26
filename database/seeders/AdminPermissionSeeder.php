<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class AdminPermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->permissions() as $permission) {
            Permission::query()->firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }
    }

    private function permissions(): array
    {
        return [
            'client.view',
            'client.manage',
            'client.credit',
            'client_group.manage',
            'affiliate.view',
            'affiliate.manage',
            'export.data',
            'report.view',
            'announcement.manage',
            'contract.view',
            'contract.manage',
            'product.view',
            'product.manage',
            'order.view',
            'order.approve',
            'order.cancel',
            'cancel_request.manage',
            'promo.manage',
            'host.view',
            'host.manage',
            'invoice.view',
            'invoice.manage',
            'invoice.refund',
            'ticket.view',
            'ticket.manage',
            'notification.manage',
            'notification.template',
            'system_task.view',
            'admin_action_log.view',
            'plugin.manage',
            'setting.manage',
        ];
    }
}
