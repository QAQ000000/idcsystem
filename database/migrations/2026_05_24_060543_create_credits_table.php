<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('credits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->string('type', 20)->comment('add,deduct');
            $table->decimal('amount', 10, 2);
            $table->decimal('balance', 10, 2);
            $table->string('description', 255)->nullable();
            $table->string('rel_type', 50)->nullable();
            $table->unsignedBigInteger('rel_id')->default(0);
            $table->timestamps();
            $table->index('client_id');
            $table->foreign('client_id')->references('id')->on('clients');
        });
    }
    public function down(): void { Schema::dropIfExists('credits'); }
};