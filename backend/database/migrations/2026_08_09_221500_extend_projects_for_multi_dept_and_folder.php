<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->date('starts_at')->nullable()->after('status');
            $table->date('ends_at')->nullable()->after('starts_at');
            $table->foreignId('root_folder_id')->nullable()->after('ends_at')
                ->constrained('folders')->nullOnDelete();
        });

        Schema::create('department_project', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['department_id', 'project_id']);
        });

        Schema::table('folders', function (Blueprint $table) {
            $table->boolean('is_project_root')->default(false)->after('created_by');
        });
    }

    public function down(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->dropColumn('is_project_root');
        });

        Schema::dropIfExists('department_project');

        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('root_folder_id');
            $table->dropColumn(['starts_at', 'ends_at']);
        });
    }
};
