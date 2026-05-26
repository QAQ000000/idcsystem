<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ticket_slas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('department_id')->nullable()->constrained('ticket_departments')->nullOnDelete();
            $table->enum('priority', ['Low', 'Medium', 'High', 'Urgent'])->default('Medium');
            $table->integer('response_time_minutes');
            $table->integer('resolution_time_minutes');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['department_id', 'priority', 'active']);
        });

        Schema::create('ticket_sla_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sla_id')->constrained('ticket_slas')->cascadeOnDelete();
            $table->timestamp('response_due_at')->nullable();
            $table->timestamp('resolution_due_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->boolean('response_breached')->default(false);
            $table->boolean('resolution_breached')->default(false);
            $table->timestamps();
            $table->index(['ticket_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_sla_logs');
        Schema::dropIfExists('ticket_slas');
    }
};
