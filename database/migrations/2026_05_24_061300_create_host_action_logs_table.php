<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('host_action_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_id')->constrained('hosts')->cascadeOnDelete();
            $table->string('action', 50);
            $table->string('message', 255)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['host_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('host_action_logs');
    }
};
