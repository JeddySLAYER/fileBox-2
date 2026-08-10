<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('validations', function (Blueprint $table) {
            $table->unsignedInteger('sla_hours')->nullable()->after('comment');
            $table->timestamp('due_at')->nullable()->index()->after('sla_hours');
        });
    }

    public function down(): void
    {
        Schema::table('validations', function (Blueprint $table) {
            $table->dropColumn(['sla_hours', 'due_at']);
        });
    }
};
