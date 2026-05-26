<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('domain');
            $table->string('tld', 20);
            $table->enum('status', ['Active', 'Pending', 'Expired', 'Cancelled', 'Transferred'])->default('Pending');
            $table->date('registration_date');
            $table->date('expiry_date');
            $table->boolean('auto_renew')->default(true);
            $table->boolean('whois_privacy')->default(false);
            $table->json('nameservers')->nullable();
            $table->string('registrar')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'status']);
            $table->index('expiry_date');
            $table->unique('domain');
        });

        Schema::create('domain_pricings', function (Blueprint $table): void {
            $table->id();
            $table->string('tld', 20)->unique();
            $table->decimal('register_price', 10, 2);
            $table->decimal('renew_price', 10, 2);
            $table->decimal('transfer_price', 10, 2);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_pricings');
        Schema::dropIfExists('domains');
    }
};
