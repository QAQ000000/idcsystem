<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number', 50)->unique();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('department_id');
            $table->unsignedBigInteger('status_id')->default(1);
            $table->unsignedBigInteger('assigned_to')->default(0);
            $table->string('subject', 255);
            $table->text('message');
            $table->string('priority', 20)->default('Medium');
            $table->tinyInteger('rating')->nullable();
            $table->timestamps();
            $table->index('client_id');
            $table->index('department_id');
            $table->index('status_id');
        });
    }
    public function down(): void { Schema::dropIfExists('tickets'); }
};