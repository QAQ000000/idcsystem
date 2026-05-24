<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('invoice_id')->default(0);
            $table->string('type', 20)->comment('credit,debit');
            $table->decimal('amount', 10, 2);
            $table->decimal('fee', 10, 2)->default(0.00);
            $table->string('payment_method', 50)->nullable();
            $table->string('gateway_trans_id', 255)->nullable();
            $table->string('description', 255)->nullable();
            $table->tinyInteger('refunded')->default(0);
            $table->timestamps();
            $table->index('client_id');
            $table->index('invoice_id');
            $table->foreign('client_id')->references('id')->on('clients');
        });
    }
    public function down(): void { Schema::dropIfExists('accounts'); }
};