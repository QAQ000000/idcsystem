<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->string('type', 50)->comment('product,setup,upgrade,addon');
            $table->string('description', 255);
            $table->decimal('amount', 10, 2);
            $table->unsignedBigInteger('rel_id')->default(0);
            $table->timestamps();
            $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('cascade');
        });
    }
    public function down(): void { Schema::dropIfExists('invoice_items'); }
};