<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('group_id');
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('type', 25)->comment('hosting,vps,dedicated,other');
            $table->string('pay_type', 50)->default('recurring');
            $table->string('pay_method', 20)->default('prepaid');
            $table->string('auto_setup', 20)->default('manual');
            $table->string('server_type', 50)->nullable();
            $table->unsignedBigInteger('server_group_id')->default(0);
            $table->boolean('stock_control')->default(false);
            $table->integer('stock_qty')->default(0);
            $table->json('domain_config')->nullable();
            $table->json('password_config')->nullable();
            $table->boolean('hidden')->default(false);
            $table->boolean('retired')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->integer('sort_order')->default(0);
            $table->string('api_type', 50)->nullable();
            $table->unsignedBigInteger('upstream_api_id')->default(0);
            $table->unsignedBigInteger('upstream_product_id')->default(0);
            $table->string('upstream_price_type', 20)->default('percent');
            $table->decimal('upstream_price_value', 10, 2)->default(120.00);
            $table->timestamps();
            $table->index('group_id');
            $table->index('type');
            $table->index('hidden');
        });
    }
    public function down(): void { Schema::dropIfExists('products'); }
};