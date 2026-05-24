<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('hosts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('server_id')->default(0);
            $table->string('domain', 255)->nullable();
            $table->string('username', 255)->nullable();
            $table->string('password', 255)->nullable();
            $table->string('billing_cycle', 50);
            $table->decimal('first_payment_amount', 10, 2)->default(0.00);
            $table->decimal('recurring_amount', 10, 2)->default(0.00);
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('next_due_date')->nullable();
            $table->timestamp('next_invoice_date')->nullable();
            $table->timestamp('termination_date')->nullable();
            $table->string('status', 20)->default('Pending');
            $table->boolean('auto_renew')->default(false);
            $table->text('suspend_reason')->nullable();
            $table->text('notes')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamps();
            $table->index('client_id');
            $table->index('product_id');
            $table->index('status');
            $table->index('next_due_date');
            $table->foreign('client_id')->references('id')->on('clients');
            $table->foreign('order_id')->references('id')->on('orders');
            $table->foreign('product_id')->references('id')->on('products');
        });
    }
    public function down(): void { Schema::dropIfExists('hosts'); }
};