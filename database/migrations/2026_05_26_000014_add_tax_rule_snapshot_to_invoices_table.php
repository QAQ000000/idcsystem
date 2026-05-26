<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('tax_rule_id')->nullable()->after('tax_rate');
            $table->string('tax_rule_name')->nullable()->after('tax_rule_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn(['tax_rule_id', 'tax_rule_name']);
        });
    }
};
