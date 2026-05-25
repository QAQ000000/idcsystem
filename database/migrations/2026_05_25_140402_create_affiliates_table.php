<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('affiliates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('code', 32)->unique();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->decimal('balance', 10, 2)->default(0);
            $table->decimal('withdrawn', 10, 2)->default(0);
            $table->integer('referral_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliates');
    }
};
