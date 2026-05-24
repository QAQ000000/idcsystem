<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50)->comment('product,client');
            $table->unsignedBigInteger('rel_id');
            $table->string('field_name', 100);
            $table->string('field_type', 50)->comment('text,password,dropdown,textarea');
            $table->text('description')->nullable();
            $table->text('options')->nullable();
            $table->boolean('required')->default(false);
            $table->boolean('admin_only')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['type', 'rel_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('custom_fields'); }
};