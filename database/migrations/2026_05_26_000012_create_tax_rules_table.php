<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('country_code', 2);
            $table->string('state_code', 10)->nullable();
            $table->decimal('rate', 5, 2);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['country_code', 'state_code', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rules');
    }
};
