<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('client_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->string('color', 20)->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('client_groups'); }
};