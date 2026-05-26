<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('financial_statements', function (Blueprint $table): void {
            $table->id();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('total_income', 12, 2)->default(0);
            $table->decimal('total_refund', 12, 2)->default(0);
            $table->decimal('total_commission', 12, 2)->default(0);
            $table->decimal('net_income', 12, 2)->default(0);
            $table->integer('paid_invoice_count')->default(0);
            $table->integer('refund_count')->default(0);
            $table->json('breakdown')->nullable();
            $table->timestamps();

            $table->index(['period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_statements');
    }
};
