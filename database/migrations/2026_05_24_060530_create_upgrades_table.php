<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('upgrades', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('host_id');
            $table->string('type', 20)->comment('upgrade,downgrade');
            $table->unsignedBigInteger('from_product_id')->nullable();
            $table->unsignedBigInteger('to_product_id')->nullable();
            $table->decimal('amount', 10, 2)->default(0.00);
            $table->string('status', 30)->default('Pending');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->foreign('host_id')->references('id')->on('hosts');
        });
    }
    public function down(): void { Schema::dropIfExists('upgrades'); }
};