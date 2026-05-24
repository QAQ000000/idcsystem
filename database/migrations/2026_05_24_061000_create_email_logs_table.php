<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->string('to', 255);
            $table->string('subject', 255);
            $table->longText('body')->nullable();
            $table->string('template', 100)->nullable();
            $table->string('provider', 100)->nullable();
            $table->string('status', 20)->default('pending')->comment('pending,sent,failed');
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
        Schema::dropIfExists('email_logs');
    }
};
