<?php

namespace Database\Seeders;

use App\Modules\User\Models\ClientTag;
use Illuminate\Database\Seeder;

class ClientTagSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['name' => 'VIP', 'slug' => 'vip', 'color' => '#F59E0B'],
            ['name' => '高价值', 'slug' => 'high-value', 'color' => '#10B981'],
            ['name' => '风险', 'slug' => 'risk', 'color' => '#EF4444'],
        ] as $tag) {
            ClientTag::query()->updateOrCreate(
                ['slug' => $tag['slug']],
                $tag + ['system' => true]
            );
        }
    }
}
