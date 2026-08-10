<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->longText('ai_analysis')->nullable()->after('summary');
            $table->timestamp('ai_processed_at')->nullable()->after('ai_analysis');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['ai_analysis', 'ai_processed_at']);
        });
    }
};
