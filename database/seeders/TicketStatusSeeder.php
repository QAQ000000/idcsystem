<?php

namespace Database\Seeders;

use App\Modules\Ticket\Models\TicketStatus;
use Illuminate\Database\Seeder;

class TicketStatusSeeder extends Seeder
{
    public function run(): void
    {
        TicketStatus::query()->update(['is_default' => false]);

        foreach ($this->statuses() as $status) {
            TicketStatus::query()->updateOrCreate(
                ['name' => $status['name']],
                $status
            );
        }
    }

    private function statuses(): array
    {
        return [
            [
                'name' => 'Open',
                'color' => '#16a34a',
                'show_client' => true,
                'is_default' => true,
                'sort_order' => 10,
            ],
            [
                'name' => 'Answered',
                'color' => '#2563eb',
                'show_client' => true,
                'is_default' => false,
                'sort_order' => 20,
            ],
            [
                'name' => 'Customer Reply',
                'color' => '#f59e0b',
                'show_client' => true,
                'is_default' => false,
                'sort_order' => 30,
            ],
            [
                'name' => 'Closed',
                'color' => '#64748b',
                'show_client' => true,
                'is_default' => false,
                'sort_order' => 40,
            ],
        ];
    }
}
