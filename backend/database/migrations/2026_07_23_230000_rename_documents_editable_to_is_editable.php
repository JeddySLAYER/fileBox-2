<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('documents', 'editable') && ! Schema::hasColumn('documents', 'is_editable')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->renameColumn('editable', 'is_editable');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('documents', 'is_editable') && ! Schema::hasColumn('documents', 'editable')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->renameColumn('is_editable', 'editable');
            });
        }
    }
};
