<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('field_id');
            $table->unsignedBigInteger('rel_id');
            $table->text('value')->nullable();
            $table->timestamps();
            $table->foreign('field_id')->references('id')->on('custom_fields')->onDelete('cascade');
            $table->index('rel_id');
        });
    }
    public function down(): void { Schema::dropIfExists('custom_field_values'); }
};