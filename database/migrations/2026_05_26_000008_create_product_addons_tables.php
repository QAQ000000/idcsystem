<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_addons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('billing_cycle', ['one_time', 'recurring'])->default('recurring');
            $table->decimal('price', 10, 2);
            $table->integer('stock_qty')->nullable();
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['product_id', 'active']);
        });

        Schema::create('host_addons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('host_id')->constrained()->cascadeOnDelete();
            $table->foreignId('addon_id')->constrained('product_addons')->cascadeOnDelete();
            $table->decimal('price', 10, 2);
            $table->enum('billing_cycle', ['one_time', 'recurring']);
            $table->enum('status', ['Active', 'Suspended', 'Terminated'])->default('Active');
            $table->timestamp('next_due_date')->nullable();
            $table->timestamps();
            $table->index(['host_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('host_addons');
        Schema::dropIfExists('product_addons');
    }
};
