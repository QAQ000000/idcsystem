<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('config_options', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('group_id');
            $table->string('option_name', 255);
            $table->tinyInteger('option_type')->comment('1:dropdown 2:radio 3:checkbox 4:quantity');
            $table->text('description')->nullable();
            $table->boolean('hidden')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->foreign('group_id')->references('id')->on('config_groups')->onDelete('cascade');
        });
    }
    public function down(): void { Schema::dropIfExists('config_options'); }
};