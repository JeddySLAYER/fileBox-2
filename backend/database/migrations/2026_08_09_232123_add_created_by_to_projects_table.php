<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('manager_id')->constrained('users')->nullOnDelete();
        });

        // Backfill depuis le dossier racine ou le manager
        DB::statement('
            UPDATE projects
            SET created_by = COALESCE(
                (SELECT folders.created_by FROM folders WHERE folders.id = projects.root_folder_id),
                projects.manager_id
            )
            WHERE created_by IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
