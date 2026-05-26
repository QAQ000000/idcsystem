<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->integer('credit_score')->default(100)->after('credit_limit');
            $table->enum('credit_level', ['Excellent', 'Good', 'Fair', 'Poor'])->default('Good')->after('credit_score');
            $table->timestamp('credit_score_updated_at')->nullable()->after('credit_level');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('requires_approval')->default(false)->after('status');
        });

        Schema::create('credit_score_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->integer('old_score');
            $table->integer('new_score');
            $table->string('reason');
            $table->string('event_key')->nullable()->unique();
            $table->json('details')->nullable();
            $table->timestamp('created_at');
            $table->index(['client_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_score_logs');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('requires_approval');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['credit_score', 'credit_level', 'credit_score_updated_at']);
        });
    }
};
