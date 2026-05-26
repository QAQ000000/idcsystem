<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ticket_assignment_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('department_id')->nullable()->constrained('ticket_departments')->nullOnDelete();
            $table->enum('strategy', ['round_robin', 'least_active', 'random'])->default('least_active');
            $table->json('admin_user_ids');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['department_id', 'active']);
        });

        Schema::table('admin_users', function (Blueprint $table): void {
            $table->integer('assigned_ticket_count')->default(0)->after('two_factor_secret');
        });
    }

    public function down(): void
    {
        Schema::table('admin_users', function (Blueprint $table): void {
            $table->dropColumn('assigned_ticket_count');
        });

        Schema::dropIfExists('ticket_assignment_rules');
    }
};
