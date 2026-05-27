<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translations', function (Blueprint $table): void {
            $table->id();
            $table->string('locale', 10);
            $table->string('group');
            $table->string('key');
            $table->text('value');
            $table->boolean('is_translated')->default(false);
            $table->string('translated_by')->nullable();
            $table->timestamps();

            $table->unique(['locale', 'group', 'key']);
            $table->index(['locale', 'group']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translations');
    }
};
