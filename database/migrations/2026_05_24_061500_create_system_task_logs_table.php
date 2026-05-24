<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('system_task_logs', function (Blueprint $table) {
            $table->id();
            $table->string('task_name', 100);
            $table->string('status', 20);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->longText('output')->nullable();
            $table->longText('error')->nullable();
            $table->timestamps();
            $table->index(['task_name', 'started_at']);
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_task_logs');
    }
};
