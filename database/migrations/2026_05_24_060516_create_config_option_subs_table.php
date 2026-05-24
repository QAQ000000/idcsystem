<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('config_option_subs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('config_option_id');
            $table->string('option_name', 255);
            $table->integer('sort_order')->default(0);
            $table->boolean('hidden')->default(false);
            $table->timestamps();
            $table->foreign('config_option_id')->references('id')->on('config_options')->onDelete('cascade');
        });
    }
    public function down(): void { Schema::dropIfExists('config_option_subs'); }
};