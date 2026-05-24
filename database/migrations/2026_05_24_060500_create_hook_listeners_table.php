<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('hook_listeners', function (Blueprint $table) {
            $table->id();
            $table->string('hook', 100);
            $table->string('plugin', 100);
            $table->string('class', 255);
            $table->integer('priority')->default(10);
            $table->timestamps();
            $table->index('hook');
        });
    }
    public function down(): void { Schema::dropIfExists('hook_listeners'); }
};