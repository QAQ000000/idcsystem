<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('host_config_options', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('host_id');
            $table->unsignedBigInteger('config_option_id');
            $table->unsignedBigInteger('config_option_sub_id')->default(0);
            $table->integer('qty')->default(1);
            $table->timestamps();
            $table->foreign('host_id')->references('id')->on('hosts')->onDelete('cascade');
        });
    }
    public function down(): void { Schema::dropIfExists('host_config_options'); }
};