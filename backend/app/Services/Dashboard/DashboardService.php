<?php

namespace App\Services\Dashboard;

use App\Enums\DocumentStatus;
use App\Enums\ValidationStatus;
use App\Models\Document;
use App\Models\Folder;
use App\Models\Project;
use App\Models\User;
use App\Models\Validation;
use App\Services\Access\AccessService;
use App\Services\ActivityLog\ActivityLogService;
use App\Support\ReportingScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DashboardService
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
        private readonly AccessService $accessService,
    ) {}

    /**
     * Accueil pour utilisateurs sans KPIs (collaborateur, invité…).
     *
     * @return array<string, mixed>
     */
    public function home(User $user): array
    {
        $documents = $this->accessibleDocumentsQuery($user)
            ->with(['author:id,name', 'folder:id,name'])
            ->latest()
            ->limit(8)
            ->get(['id', 'reference', 'title', 'status', 'author_id', 'folder_id', 'created_at']);

        $folders = $this->accessibleFoldersQuery($user)
            ->latest('updated_at')
            ->limit(8)
            ->get(['id', 'name', 'parent_id', 'project_id', 'department_id', 'updated_at']);

        $projectsQuery = Project::query()
            ->where(function (Builder $q) use ($user) {
                $q->where('manager_id', $user->id)
                    ->orWhereHas('members', fn (Builder $m) => $m->where('users.id', $user->id));
            });

        $projects = (clone $projectsQuery)
            ->latest('updated_at')
            ->limit(8)
            ->get(['id', 'name', 'code', 'status', 'updated_at']);

        return [
            'scope' => [
                'mode' => 'home',
                'label' => 'Mes ressources',
                'department_ids' => [],
                'project_ids' => [],
            ],
            'counts' => [
                'documents' => $this->accessibleDocumentsQuery($user)->count(),
                'folders' => $this->accessibleFoldersQuery($user)->count(),
                'projects' => (clone $projectsQuery)->count(),
                'users' => 0,
                'users_active' => 0,
                'documents_trashed' => 0,
                'validations_pending' => 0,
            ],
            'documents_by_status' => [],
            'recent_documents' => $documents,
            'recent_folders' => $folders,
            'recent_projects' => $projects,
            'pending_validations' => [],
            'recent_activity' => [],
        ];
    }

    private function accessibleDocumentsQuery(User $user): Builder
    {
        $query = Document::query();

        if (! $user->hasPermission('documents.view')) {
            $ids = $this->accessService->accessibleDocumentIds($user);

            return $query->whereIn('id', $ids ?: [0]);
        }

        return $query;
    }

    private function accessibleFoldersQuery(User $user): Builder
    {
        $query = Folder::query();

        if (! $user->hasPermission('folders.view')) {
            $ids = $this->accessService->accessibleFolderIds($user);

            return $query->whereIn('id', $ids ?: [0]);
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(User $user): array
    {
        $scope = new ReportingScope($user);

        if (! $scope->canAccess()) {
            throw ValidationException::withMessages([
                'dashboard' => ['Vous n\'avez pas accès au tableau de bord.'],
            ]);
        }

        $documentsByStatus = $this->scopedDocuments($scope)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'scope' => $scope->meta(),
            'counts' => [
                'users' => $this->countUsers($scope),
                'users_active' => $this->countUsers($scope, activeOnly: true),
                'documents' => $this->scopedDocuments($scope)->count(),
                'documents_trashed' => $this->scopedDocuments($scope, withTrashed: true)->onlyTrashed()->count(),
                'folders' => $this->scopedFolders($scope)->count(),
                'projects' => $this->countProjects($scope),
                'validations_pending' => $this->scopedPendingValidations($scope)->count(),
            ],
            'documents_by_status' => collect(DocumentStatus::cases())
                ->mapWithKeys(fn (DocumentStatus $status) => [
                    $status->value => (int) ($documentsByStatus[$status->value] ?? 0),
                ])
                ->all(),
            'recent_documents' => $this->scopedDocuments($scope)
                ->with(['author:id,name', 'folder:id,name'])
                ->latest()
                ->limit(8)
                ->get(['id', 'reference', 'title', 'status', 'author_id', 'folder_id', 'created_at']),
            'pending_validations' => $this->scopedPendingValidations($scope)
                ->with([
                    'document:id,reference,title,status',
                    'workflowStep:id,name,step_order',
                ])
                ->latest()
                ->limit(8)
                ->get(),
            'recent_activity' => $this->activityLog
                ->scopedQuery($scope)
                ->with('user:id,name')
                ->latest()
                ->limit(10)
                ->get(['id', 'user_id', 'action', 'description', 'created_at']),
        ];
    }

    private function scopedDocuments(ReportingScope $scope, bool $withTrashed = false): Builder
    {
        $query = $withTrashed ? Document::withTrashed() : Document::query();

        return $this->applyResourceScope($query, $scope);
    }

    private function scopedFolders(ReportingScope $scope): Builder
    {
        return $this->applyResourceScope(Folder::query(), $scope);
    }

    private function applyResourceScope(Builder $query, ReportingScope $scope): Builder
    {
        return match ($scope->mode()) {
            ReportingScope::MODE_DEPARTMENT => $query->whereIn('department_id', $scope->departmentIds() ?: [0]),
            ReportingScope::MODE_PROJECT => $query->whereIn('project_id', $scope->projectIds() ?: [0]),
            default => $query,
        };
    }

    private function countUsers(ReportingScope $scope, bool $activeOnly = false): int
    {
        if ($scope->isGlobal()) {
            $query = User::query();
            if ($activeOnly) {
                $query->where('is_active', true);
            }

            return $query->count();
        }

        if ($scope->mode() === ReportingScope::MODE_DEPARTMENT) {
            $query = User::query()->whereIn('department_id', $scope->departmentIds() ?: [0]);
            if ($activeOnly) {
                $query->where('is_active', true);
            }

            return $query->count();
        }

        // chef de projet : membres des projets managés
        $projectIds = $scope->projectIds();
        if ($projectIds === []) {
            return 0;
        }

        $query = User::query()->whereHas('projects', fn (Builder $q) => $q->whereIn('projects.id', $projectIds));
        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $query->count();
    }

    private function countProjects(ReportingScope $scope): int
    {
        return match ($scope->mode()) {
            ReportingScope::MODE_DEPARTMENT => Project::query()
                ->whereIn('department_id', $scope->departmentIds() ?: [0])
                ->count(),
            ReportingScope::MODE_PROJECT => count($scope->projectIds()),
            default => Project::query()->count(),
        };
    }

    private function scopedPendingValidations(ReportingScope $scope): Builder
    {
        $query = Validation::query()->where('status', ValidationStatus::Pending->value);

        if ($scope->isGlobal()) {
            return $query;
        }

        return $query->whereHas('document', function (Builder $q) use ($scope) {
            $this->applyResourceScope($q, $scope);
        });
    }
}
