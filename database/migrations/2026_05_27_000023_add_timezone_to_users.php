<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('timezone', 50)->default('Asia/Shanghai')->after('locale');
        });

        Schema::table('admin_users', function (Blueprint $table): void {
            $table->string('timezone', 50)->default('Asia/Shanghai')->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('timezone');
        });

        Schema::table('admin_users', function (Blueprint $table): void {
            $table->dropColumn('timezone');
        });
    }
};
