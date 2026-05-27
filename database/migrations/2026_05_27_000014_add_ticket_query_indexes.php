<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->index(['client_id', 'created_at'], 'idx_tickets_client_created');
            $table->index(['status_id', 'created_at'], 'idx_tickets_status_created');
            $table->index(['department_id', 'status_id'], 'idx_tickets_department_status');
            $table->index(['assigned_to', 'status_id'], 'idx_tickets_assigned_status');
        });

        Schema::table('ticket_replies', function (Blueprint $table): void {
            $table->index(['ticket_id', 'created_at'], 'idx_ticket_replies_ticket_created');
        });

        Schema::table('ticket_sla_logs', function (Blueprint $table): void {
            $table->index(['response_breached', 'resolution_breached'], 'idx_ticket_sla_logs_breach_flags');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_sla_logs', function (Blueprint $table): void {
            $table->dropIndex('idx_ticket_sla_logs_breach_flags');
        });

        Schema::table('ticket_replies', function (Blueprint $table): void {
            $table->dropIndex('idx_ticket_replies_ticket_created');
        });

        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropIndex('idx_tickets_assigned_status');
            $table->dropIndex('idx_tickets_department_status');
            $table->dropIndex('idx_tickets_status_created');
            $table->dropIndex('idx_tickets_client_created');
        });
    }
};
