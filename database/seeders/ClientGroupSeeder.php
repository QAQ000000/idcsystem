<?php

namespace Database\Seeders;

use App\Modules\User\Models\ClientGroup;
use Illuminate\Database\Seeder;

class ClientGroupSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->groups() as $group) {
            ClientGroup::query()->updateOrCreate(
                ['name' => $group['name']],
                $group
            );
        }
    }

    private function groups(): array
    {
        return [
            [
                'name' => '普通客户',
                'discount_percent' => 0,
                'color' => '#64748b',
            ],
            [
                'name' => 'VIP客户',
                'discount_percent' => 5,
                'color' => '#2563eb',
            ],
            [
                'name' => '企业客户',
                'discount_percent' => 10,
                'color' => '#16a34a',
            ],
        ];
    }
}
