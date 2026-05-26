<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ticket_replies', function (Blueprint $table): void {
            $table->json('attachment')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('ticket_replies', function (Blueprint $table): void {
            $table->string('attachment', 255)->nullable()->change();
        });
    }
};
