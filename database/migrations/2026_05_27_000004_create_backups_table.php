<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table): void {
            $table->id();
            $table->enum('type', ['database', 'files'])->default('database');
            $table->string('file_path');
            $table->bigInteger('file_size')->default(0);
            $table->enum('status', ['completed', 'failed'])->default('completed');
            $table->text('error_message')->nullable();
            $table->timestamp('created_at');
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
