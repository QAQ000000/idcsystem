<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->string('order_number', 100)->unique();
            $table->string('status', 30)->default('Pending');
            $table->decimal('amount', 10, 2)->default(0.00);
            $table->unsignedBigInteger('currency_id')->default(1);
            $table->string('payment_method', 50)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('promo_code', 100)->nullable();
            $table->decimal('promo_value', 10, 2)->default(0.00);
            $table->unsignedBigInteger('invoice_id')->default(0);
            $table->text('notes')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('client_id');
            $table->index('status');
            $table->foreign('client_id')->references('id')->on('clients');
        });
    }
    public function down(): void { Schema::dropIfExists('orders'); }
};