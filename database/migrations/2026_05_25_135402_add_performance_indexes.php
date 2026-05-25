<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('hosts', function (Blueprint $table) {
            $table->index(['status', 'next_due_date'], 'idx_hosts_status_due');
            $table->index(['status', 'next_invoice_date', 'auto_renew'], 'idx_hosts_billing');
            $table->index(['client_id', 'status'], 'idx_hosts_client_status');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->index(['client_id', 'status'], 'idx_invoices_client_status');
            $table->index(['status', 'due_date'], 'idx_invoices_status_due');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->index(['invoice_id', 'type'], 'idx_accounts_invoice_type');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropIndex('idx_accounts_invoice_type');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('idx_invoices_status_due');
            $table->dropIndex('idx_invoices_client_status');
        });

        Schema::table('hosts', function (Blueprint $table) {
            $table->dropIndex('idx_hosts_client_status');
            $table->dropIndex('idx_hosts_billing');
            $table->dropIndex('idx_hosts_status_due');
        });
    }
};
