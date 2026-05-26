<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ssl_certificates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('host_id')->nullable()->constrained()->nullOnDelete();
            $table->string('domain');
            $table->enum('type', ['paid', 'letsencrypt'])->default('paid');
            $table->enum('status', ['Active', 'Pending', 'Expired', 'Cancelled'])->default('Pending');
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->text('certificate')->nullable();
            $table->text('private_key')->nullable();
            $table->text('ca_bundle')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->timestamps();
            $table->index(['client_id', 'status']);
            $table->index('expiry_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ssl_certificates');
    }
};
