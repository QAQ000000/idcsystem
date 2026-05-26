<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('login_attempts', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->string('ip', 45);
            $table->string('user_agent', 500)->nullable();
            $table->enum('status', ['success', 'failed'])->default('failed');
            $table->string('failure_reason')->nullable();
            $table->timestamp('created_at');
            $table->index(['email', 'created_at']);
            $table->index(['ip', 'created_at']);
        });

        Schema::table('clients', function (Blueprint $table): void {
            $table->timestamp('locked_until')->nullable()->after('last_login_ip');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('locked_until');
        });

        Schema::dropIfExists('login_attempts');
    }
};
