<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('ticket_replies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id');
            $table->string('author_type', 20)->comment('client,admin');
            $table->unsignedBigInteger('author_id');
            $table->text('message');
            $table->string('attachment', 255)->nullable();
            $table->timestamps();
            $table->foreign('ticket_id')->references('id')->on('tickets')->onDelete('cascade');
        });
    }
    public function down(): void { Schema::dropIfExists('ticket_replies'); }
};