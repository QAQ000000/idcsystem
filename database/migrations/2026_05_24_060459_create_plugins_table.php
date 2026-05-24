<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('plugins', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('title', 200);
            $table->string('type', 50)->comment('gateway,oauth,sms,email,captcha,certification,server');
            $table->string('version', 20)->default('1.0.0');
            $table->string('author', 100)->nullable();
            $table->text('description')->nullable();
            $table->tinyInteger('status')->default(0)->comment('0:disabled 1:enabled');
            $table->json('config')->nullable();
            $table->timestamps();
            $table->index('type');
            $table->index('status');
        });
    }
    public function down(): void { Schema::dropIfExists('plugins'); }
};