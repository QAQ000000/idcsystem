<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('color', 7)->default('#3B82F6');
            $table->text('description')->nullable();
            $table->boolean('system')->default(false);
            $table->timestamps();
        });

        Schema::create('client_tag_pivot', function (Blueprint $table): void {
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_tag_id')->constrained('client_tags')->cascadeOnDelete();
            $table->timestamp('tagged_at');
            $table->primary(['client_id', 'client_tag_id']);
        });

        Schema::create('tag_auto_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_tag_id')->constrained('client_tags')->cascadeOnDelete();
            $table->enum('condition_type', ['total_spent', 'order_count', 'overdue_count', 'credit_balance']);
            $table->enum('operator', ['>', '>=', '<', '<=', '=']);
            $table->decimal('threshold', 10, 2);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tag_auto_rules');
        Schema::dropIfExists('client_tag_pivot');
        Schema::dropIfExists('client_tags');
    }
};
