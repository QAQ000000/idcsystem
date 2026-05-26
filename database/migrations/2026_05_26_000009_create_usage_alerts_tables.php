<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('usage_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('host_id')->constrained()->cascadeOnDelete();
            $table->enum('metric', ['cpu', 'memory', 'disk', 'bandwidth']);
            $table->integer('threshold');
            $table->boolean('active')->default(true);
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamps();
            $table->unique(['host_id', 'metric']);
        });

        Schema::create('usage_alert_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alert_id')->constrained('usage_alerts')->cascadeOnDelete();
            $table->foreignId('host_id')->constrained()->cascadeOnDelete();
            $table->string('metric');
            $table->integer('threshold');
            $table->float('current_value');
            $table->timestamp('triggered_at');
            $table->timestamps();
            $table->index(['host_id', 'triggered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_alert_logs');
        Schema::dropIfExists('usage_alerts');
    }
};
