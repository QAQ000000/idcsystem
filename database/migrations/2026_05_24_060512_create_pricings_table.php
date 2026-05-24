<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('pricings', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50)->comment('product,configoption');
            $table->unsignedBigInteger('rel_id');
            $table->unsignedBigInteger('currency_id');
            $table->decimal('monthly', 10, 2)->default(-1);
            $table->decimal('monthly_setup', 10, 2)->default(0);
            $table->decimal('quarterly', 10, 2)->default(-1);
            $table->decimal('quarterly_setup', 10, 2)->default(0);
            $table->decimal('semiannually', 10, 2)->default(-1);
            $table->decimal('semiannually_setup', 10, 2)->default(0);
            $table->decimal('annually', 10, 2)->default(-1);
            $table->decimal('annually_setup', 10, 2)->default(0);
            $table->decimal('biennially', 10, 2)->default(-1);
            $table->decimal('biennially_setup', 10, 2)->default(0);
            $table->decimal('triennially', 10, 2)->default(-1);
            $table->decimal('triennially_setup', 10, 2)->default(0);
            $table->decimal('onetime', 10, 2)->default(-1);
            $table->decimal('hourly', 10, 2)->default(-1);
            $table->decimal('daily', 10, 2)->default(-1);
            $table->timestamps();
            $table->unique(['type', 'rel_id', 'currency_id']);
            $table->index(['type', 'rel_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('pricings'); }
};