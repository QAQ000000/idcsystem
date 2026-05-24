<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('type', 30)->comment('percentage,fixed');
            $table->decimal('value', 10, 2);
            $table->string('applies_to', 50)->default('all');
            $table->json('product_ids')->nullable();
            $table->integer('max_uses')->default(0);
            $table->integer('used_count')->default(0);
            $table->boolean('once_per_client')->default(false);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('promo_codes'); }
};