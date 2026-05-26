<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (!Schema::hasColumn('products', 'stock_alert_threshold')) {
                $table->integer('stock_alert_threshold')->default(0)->after('stock_qty');
            }
            if (!Schema::hasColumn('products', 'stock_alert_enabled')) {
                $table->boolean('stock_alert_enabled')->default(false)->after('stock_alert_threshold');
            }
        });

        if (!Schema::hasTable('product_stock_alerts')) {
            Schema::create('product_stock_alerts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->integer('stock_qty');
                $table->integer('threshold');
                $table->timestamp('triggered_at');
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
                $table->index(['product_id', 'resolved_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_stock_alerts');

        Schema::table('products', function (Blueprint $table): void {
            if (Schema::hasColumn('products', 'stock_alert_threshold')) {
                $table->dropColumn('stock_alert_threshold');
            }
            if (Schema::hasColumn('products', 'stock_alert_enabled')) {
                $table->dropColumn('stock_alert_enabled');
            }
        });
    }
};
