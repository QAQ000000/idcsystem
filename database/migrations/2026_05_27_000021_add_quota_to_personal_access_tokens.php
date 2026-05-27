<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->unsignedInteger('quota_limit')->nullable()->after('ip_whitelist');
            $table->unsignedInteger('quota_used')->default(0)->after('quota_limit');
            $table->date('quota_reset_date')->nullable()->after('quota_used');
            $table->boolean('quota_exceeded')->default(false)->after('quota_reset_date');
        });

        Schema::create('api_token_usage_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('token_id')->constrained('personal_access_tokens')->cascadeOnDelete();
            $table->string('endpoint');
            $table->string('method', 10);
            $table->unsignedSmallInteger('response_code');
            $table->unsignedInteger('response_time');
            $table->timestamp('requested_at');
            $table->timestamps();

            $table->index(['token_id', 'requested_at']);
            $table->index(['endpoint', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_token_usage_logs');

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropColumn(['quota_limit', 'quota_used', 'quota_reset_date', 'quota_exceeded']);
        });
    }
};
