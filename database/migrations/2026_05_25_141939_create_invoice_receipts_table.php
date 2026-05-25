<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invoice_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['plain', 'vat'])->default('plain');
            $table->string('title');
            $table->string('tax_number', 50)->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account')->nullable();
            $table->string('company_address')->nullable();
            $table->string('company_phone')->nullable();
            $table->string('email');
            $table->enum('status', ['pending', 'processing', 'issued', 'rejected'])->default('pending');
            $table->text('admin_notes')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status'], 'idx_receipts_client_status');
            $table->index(['invoice_id', 'status'], 'idx_receipts_invoice_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_receipts');
    }
};
