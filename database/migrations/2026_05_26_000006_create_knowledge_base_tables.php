<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('kb_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('kb_articles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained('kb_categories')->cascadeOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->longText('content');
            $table->integer('views')->default(0);
            $table->integer('helpful_count')->default(0);
            $table->integer('not_helpful_count')->default(0);
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['category_id', 'active', 'sort_order']);
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('kb_articles', function (Blueprint $table): void {
                $table->fullText(['title', 'content']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_articles');
        Schema::dropIfExists('kb_categories');
    }
};
