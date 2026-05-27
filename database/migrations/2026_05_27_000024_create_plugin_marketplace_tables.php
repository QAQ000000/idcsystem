<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_plugins', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('type', 50);
            $table->string('version', 20)->default('1.0.0');
            $table->string('author', 100)->nullable();
            $table->string('author_url')->nullable();
            $table->string('download_url')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->unsignedInteger('downloads_count')->default(0);
            $table->decimal('rating', 3, 2)->default(0);
            $table->unsignedInteger('reviews_count')->default(0);
            $table->json('screenshots')->nullable();
            $table->json('requirements')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamps();

            $table->unique(['name', 'type']);
            $table->index(['type', 'is_verified']);
            $table->index('downloads_count');
            $table->index('rating');
        });

        Schema::create('plugin_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketplace_plugin_id')->constrained('marketplace_plugins')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['marketplace_plugin_id', 'client_id']);
            $table->index('rating');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_reviews');
        Schema::dropIfExists('marketplace_plugins');
    }
};
