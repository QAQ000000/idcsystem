<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('affiliate_link_clicks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->string('ip', 45);
            $table->string('user_agent')->nullable();
            $table->string('referer')->nullable();
            $table->timestamp('clicked_at');
            $table->index(['affiliate_id', 'clicked_at']);
        });

        Schema::table('affiliates', function (Blueprint $table): void {
            $table->integer('total_clicks')->default(0)->after('withdrawn');
            $table->integer('total_signups')->default(0)->after('total_clicks');
        });
    }

    public function down(): void
    {
        Schema::table('affiliates', function (Blueprint $table): void {
            $table->dropColumn(['total_clicks', 'total_signups']);
        });

        Schema::dropIfExists('affiliate_link_clicks');
    }
};
