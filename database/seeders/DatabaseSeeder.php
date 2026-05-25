<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            CurrencySeeder::class,
            TicketStatusSeeder::class,
            TicketDepartmentSeeder::class,
            AdminPermissionSeeder::class,
            AdminUserSeeder::class,
            ClientGroupSeeder::class,
            EmailTemplateSeeder::class,
            SmsTemplateSeeder::class,
            DemoDataSeeder::class,
        ]);
    }
}
