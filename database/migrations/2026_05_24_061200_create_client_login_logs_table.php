<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_login_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('ip', 50)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('logged_in_at')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'logged_in_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_login_logs');
    }
};
