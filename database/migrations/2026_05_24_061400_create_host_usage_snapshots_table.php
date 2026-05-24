<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('host_usage_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_id')->constrained('hosts')->cascadeOnDelete();
            $table->decimal('cpu', 8, 2)->nullable();
            $table->decimal('memory', 10, 2)->nullable();
            $table->decimal('disk', 10, 2)->nullable();
            $table->decimal('bandwidth', 10, 2)->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamps();
            $table->index(['host_id', 'collected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('host_usage_snapshots');
    }
};
