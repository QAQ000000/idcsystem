<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('due_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('host_id')->constrained()->cascadeOnDelete();
            $table->integer('days_before');
            $table->timestamp('sent_at');
            $table->index(['host_id', 'days_before']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('due_reminders');
    }
};
