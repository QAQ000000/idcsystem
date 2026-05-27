<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_automations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('trigger_event')->index();
            $table->json('trigger_conditions')->nullable();
            $table->json('steps');
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('executions_count')->default(0);
            $table->timestamps();
        });

        Schema::create('marketing_automation_executions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('automation_id')->constrained('marketing_automations')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->unsignedInteger('current_step')->default(0);
            $table->string('status')->index();
            $table->json('context')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('marketing_automation_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('execution_id')->constrained('marketing_automation_executions')->cascadeOnDelete();
            $table->unsignedInteger('step_index');
            $table->string('action');
            $table->string('status');
            $table->text('message')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['execution_id', 'step_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_automation_logs');
        Schema::dropIfExists('marketing_automation_executions');
        Schema::dropIfExists('marketing_automations');
    }
};
