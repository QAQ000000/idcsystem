<?php

namespace Database\Seeders;

use App\Modules\Ticket\Models\TicketDepartment;
use Illuminate\Database\Seeder;

class TicketDepartmentSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->departments() as $department) {
            TicketDepartment::query()->updateOrCreate(
                ['name' => $department['name']],
                $department
            );
        }
    }

    private function departments(): array
    {
        return [
            [
                'name' => '技术支持',
                'email' => 'support@example.com',
                'auto_response' => '我们已收到您的技术支持请求。',
                'allow_client_open' => true,
                'require_login' => true,
                'sort_order' => 10,
            ],
            [
                'name' => '财务部门',
                'email' => 'billing@example.com',
                'auto_response' => '我们已收到您的财务请求。',
                'allow_client_open' => true,
                'require_login' => true,
                'sort_order' => 20,
            ],
            [
                'name' => '销售咨询',
                'email' => 'sales@example.com',
                'auto_response' => '我们已收到您的销售咨询。',
                'allow_client_open' => true,
                'require_login' => true,
                'sort_order' => 30,
            ],
        ];
    }
}
