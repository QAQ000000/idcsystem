<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_segments', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type', 20);
            $table->json('rules')->nullable();
            $table->unsignedInteger('clients_count')->default(0);
            $table->timestamp('last_calculated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('client_segment_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('segment_id')->constrained('client_segments')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->timestamp('added_at');
            $table->unique(['segment_id', 'client_id']);
            $table->index(['client_id', 'segment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_segment_members');
        Schema::dropIfExists('client_segments');
    }
};
