<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 50);
            $table->string('template', 100)->nullable();
            $table->string('template_code', 100)->nullable();
            $table->text('content')->nullable();
            $table->string('provider', 100)->nullable();
            $table->string('status', 20)->default('pending')->comment('pending,processing,sent,failed');
            $table->boolean('success')->default(false);
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['template', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('provider');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
    }
};
