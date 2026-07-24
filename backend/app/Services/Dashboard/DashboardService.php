<?php

namespace App\Services\Dashboard;

use App\Enums\DocumentStatus;
use App\Enums\ValidationStatus;
use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\Folder;
use App\Models\Project;
use App\Models\User;
use App\Models\Validation;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $documentsByStatus = Document::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'counts' => [
                'users' => User::query()->count(),
                'users_active' => User::query()->where('is_active', true)->count(),
                'documents' => Document::query()->count(),
                'documents_trashed' => Document::onlyTrashed()->count(),
                'folders' => Folder::query()->count(),
                'projects' => Project::query()->count(),
                'validations_pending' => Validation::query()
                    ->where('status', ValidationStatus::Pending->value)
                    ->count(),
            ],
            'documents_by_status' => collect(DocumentStatus::cases())
                ->mapWithKeys(fn (DocumentStatus $status) => [
                    $status->value => (int) ($documentsByStatus[$status->value] ?? 0),
                ])
                ->all(),
            'recent_documents' => Document::query()
                ->with(['author:id,name', 'folder:id,name'])
                ->latest()
                ->limit(8)
                ->get(['id', 'reference', 'title', 'status', 'author_id', 'folder_id', 'created_at']),
            'pending_validations' => Validation::query()
                ->with([
                    'document:id,reference,title,status',
                    'workflowStep:id,name,step_order',
                ])
                ->where('status', ValidationStatus::Pending->value)
                ->latest()
                ->limit(8)
                ->get(),
            'recent_activity' => ActivityLog::query()
                ->with('user:id,name')
                ->latest()
                ->limit(10)
                ->get(['id', 'user_id', 'action', 'description', 'created_at']),
        ];
    }
}
