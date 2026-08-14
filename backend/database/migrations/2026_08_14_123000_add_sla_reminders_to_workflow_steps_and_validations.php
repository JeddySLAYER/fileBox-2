<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_steps', function (Blueprint $table) {
            $table->unsignedInteger('duration_hours')->nullable()->after('is_mandatory');
            $table->unsignedInteger('reminder_hours_before')->nullable()->after('duration_hours');
            $table->boolean('remind_on_overdue')->default(true)->after('reminder_hours_before');
        });

        Schema::table('validations', function (Blueprint $table) {
            $table->unsignedInteger('reminder_hours_before')->nullable()->after('due_at');
            $table->boolean('remind_on_overdue')->default(true)->after('reminder_hours_before');
            $table->timestamp('approaching_notified_at')->nullable()->after('remind_on_overdue');
            $table->timestamp('overdue_notified_at')->nullable()->after('approaching_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_steps', function (Blueprint $table) {
            $table->dropColumn(['duration_hours', 'reminder_hours_before', 'remind_on_overdue']);
        });

        Schema::table('validations', function (Blueprint $table) {
            $table->dropColumn([
                'reminder_hours_before',
                'remind_on_overdue',
                'approaching_notified_at',
                'overdue_notified_at',
            ]);
        });
    }
};
