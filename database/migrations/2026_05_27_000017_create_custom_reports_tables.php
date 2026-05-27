<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type')->default('sql');
            $table->text('query')->nullable();
            $table->json('config')->nullable();
            $table->json('columns')->nullable();
            $table->string('schedule')->nullable();
            $table->json('recipients')->nullable();
            $table->foreignId('created_by')->constrained('admin_users');
            $table->timestamps();
        });

        Schema::create('custom_report_executions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('custom_report_id')->constrained('custom_reports')->cascadeOnDelete();
            $table->unsignedInteger('rows_count')->default(0);
            $table->unsignedInteger('execution_time')->default(0);
            $table->string('status');
            $table->text('error')->nullable();
            $table->timestamp('executed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_report_executions');
        Schema::dropIfExists('custom_reports');
    }
};
