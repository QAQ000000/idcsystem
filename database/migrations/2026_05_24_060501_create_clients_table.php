<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('username', 50)->unique();
            $table->string('email', 100)->unique();
            $table->string('password', 255);
            $table->tinyInteger('status')->default(0)->comment('0:inactive 1:active 2:closed');
            $table->unsignedBigInteger('group_id')->default(0);
            $table->string('company_name', 100)->nullable();
            $table->string('phone_code', 10)->default('86');
            $table->string('phone', 50)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('address', 255)->nullable();
            $table->unsignedBigInteger('currency_id')->default(1);
            $table->decimal('credit', 12, 2)->default(0.00);
            $table->decimal('credit_limit', 12, 2)->default(0.00);
            $table->boolean('two_factor_enabled')->default(false);
            $table->string('two_factor_secret', 255)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 50)->nullable();
            $table->string('remember_token', 100)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('status');
            $table->index('group_id');
        });
    }
    public function down(): void { Schema::dropIfExists('clients'); }
};