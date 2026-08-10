<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accesses', function (Blueprint $table) {
            $table->timestamp('expiry_notified_at')->nullable()->after('ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('accesses', function (Blueprint $table) {
            $table->dropColumn('expiry_notified_at');
        });
    }
};
