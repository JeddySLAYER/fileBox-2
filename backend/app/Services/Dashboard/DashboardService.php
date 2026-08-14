<?php

namespace App\Services\Dashboard;

use App\Enums\DocumentStatus;
use App\Enums\ValidationStatus;
use App\Http\Resources\FavoriteResource;
use App\Models\Access;
use App\Models\Comment;
use App\Models\Document;
use App\Models\Folder;
use App\Models\Project;
use App\Models\User;
use App\Models\Validation;
use App\Services\Access\SpaceVisibility;
use App\Services\ActivityLog\ActivityLogService;
use App\Services\Favorite\FavoriteService;
use App\Services\Validation\ValidationService;
use App\Support\ReportingScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DashboardService
{
    /** ponytail: heuristique SLA sans due_at — upgrade: colonne deadline sur validations */
    private const BLOCKED_AFTER_DAYS = 7;

    public function __construct(
        private readonly ActivityLogService $activityLog,
        private readonly SpaceVisibility $spaceVisibility,
        private readonly FavoriteService $favoriteService,
        private readonly ValidationService $validationService,
    ) {}

    /**
     * Accueil collaborateur / invité : actionnable, pas de KPIs globaux.
     *
     * @return array<string, mixed>
     */
    public function home(User $user): array
    {
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

        $inbox = $this->validationService->inbox($user);

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
                'documents_archived' => 0,
                'validations_pending' => $inbox->count(),
                'validations_blocked' => 0,
            ],
            'documents_by_status' => [],
            'recent_documents' => $this->accessibleDocumentsQuery($user)
                ->where('status', '!=', DocumentStatus::Archived)
                ->with(['author:id,name', 'folder:id,name'])
                ->latest()
                ->limit(6)
                ->get(['id', 'reference', 'title', 'status', 'author_id', 'folder_id', 'created_at']),
            'recent_folders' => $folders,
            'recent_projects' => $projects,
            'pending_validations' => $this->serializeValidations($inbox->take(8)),
            'blocked_validations' => [],
            'shared_documents' => $this->sharedDocuments($user),
            'needs_attention' => $this->needsAttentionDocuments($user),
            'recent_comments' => $this->recentCommentsOnMyDocuments($user),
            'favorites' => FavoriteResource::collection(
                $this->favoriteService->listForUser($user)->take(6)
            )->resolve(),
            'recent_activity' => [],
        ];
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

        $pendingQuery = $this->scopedPendingValidations($scope);
        // Bloqué = échéance dépassée (due_at), sinon fallback heuristique 7j sans SLA
        $blockedQuery = (clone $pendingQuery)->where(function ($q) {
            $q->where(function ($q) {
                $q->whereNotNull('due_at')->where('due_at', '<', now());
            })->orWhere(function ($q) {
                $q->whereNull('due_at')
                    ->where('validations.created_at', '<=', now()->subDays(self::BLOCKED_AFTER_DAYS));
            });
        });

        return [
            'scope' => $scope->meta(),
            'counts' => [
                'users' => $this->countUsers($scope),
                'users_active' => $this->countUsers($scope, activeOnly: true),
                'documents' => $this->scopedDocuments($scope)->count(),
                'documents_trashed' => $this->scopedDocuments($scope, withTrashed: true)->onlyTrashed()->count(),
                'documents_archived' => $this->scopedDocuments($scope)
                    ->where('status', DocumentStatus::Archived->value)
                    ->count(),
                'folders' => $this->scopedFolders($scope)->count(),
                'projects' => $this->countProjects($scope),
                'validations_pending' => (clone $pendingQuery)->count(),
                'validations_blocked' => (clone $blockedQuery)->count(),
            ],
            'documents_by_status' => collect(DocumentStatus::cases())
                ->mapWithKeys(fn (DocumentStatus $status) => [
                    $status->value => (int) ($documentsByStatus[$status->value] ?? 0),
                ])
                ->all(),
            'recent_documents' => $this->scopedDocuments($scope)
                ->where('status', '!=', DocumentStatus::Archived)
                ->with(['author:id,name', 'folder:id,name'])
                ->latest()
                ->limit(6)
                ->get(['id', 'reference', 'title', 'status', 'author_id', 'folder_id', 'created_at']),
            'pending_validations' => $this->serializeValidations(
                (clone $pendingQuery)
                    ->with([
                        'document:id,reference,title,status',
                        'workflowStep:id,name,step_order,responsible_user_id,responsible_role_id',
                        'workflowStep.responsibleUser:id,name',
                        'workflowStep.responsibleRole:id,name,slug',
                    ])
                    ->latest()
                    ->limit(8)
                    ->get()
            ),
            'blocked_validations' => $this->serializeValidations(
                (clone $blockedQuery)
                    ->with([
                        'document:id,reference,title,status',
                        'workflowStep:id,name,step_order',
                    ])
                    ->oldest()
                    ->limit(8)
                    ->get()
            ),
            'favorites' => FavoriteResource::collection(
                $this->favoriteService->listForUser($user)->take(6)
            )->resolve(),
            'shared_documents' => [],
            'needs_attention' => [],
            'recent_comments' => [],
            'recent_activity' => $this->activityLog
                ->scopedQuery($scope)
                ->with('user:id,name')
                ->latest()
                ->limit(6)
                ->get(['id', 'user_id', 'action', 'description', 'created_at']),
        ];
    }

    private function accessibleDocumentsQuery(User $user): Builder
    {
        $query = Document::query();
        $this->spaceVisibility->applyDocumentScope($query, $user);

        return $query;
    }

    private function accessibleFoldersQuery(User $user): Builder
    {
        $query = Folder::query();
        $this->spaceVisibility->applyFolderScope($query, $user);

        return $query;
    }

    /** @return list<array<string, mixed>> */
    private function sharedDocuments(User $user): array
    {
        $docIds = Access::query()
            ->active()
            ->where('user_id', $user->id)
            ->where('accessible_type', 'document')
            ->pluck('accessible_id');

        if ($docIds->isEmpty()) {
            return [];
        }

        return Document::query()
            ->whereIn('id', $docIds)
            ->with(['folder:id,name', 'author:id,name'])
            ->latest('updated_at')
            ->limit(8)
            ->get(['id', 'reference', 'title', 'status', 'folder_id', 'author_id', 'updated_at'])
            ->all();
    }

    /** @return list<Document> */
    private function needsAttentionDocuments(User $user): array
    {
        return Document::query()
            ->where(function (Builder $q) use ($user) {
                $q->where('author_id', $user->id)->orWhere('owner_id', $user->id);
            })
            ->where(function (Builder $q) {
                $q->where('status', DocumentStatus::Rejected->value)
                    ->orWhere(function (Builder $inner) {
                        $inner->where('status', DocumentStatus::Draft->value)
                            ->whereHas('validations', fn (Builder $v) => $v->where(
                                'status',
                                ValidationStatus::CorrectionRequested->value
                            ));
                    });
            })
            ->with(['folder:id,name'])
            ->latest('updated_at')
            ->limit(8)
            ->get(['id', 'reference', 'title', 'status', 'folder_id', 'updated_at'])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function recentCommentsOnMyDocuments(User $user): array
    {
        return Comment::query()
            ->whereHas('document', function (Builder $q) use ($user) {
                $q->where('author_id', $user->id)->orWhere('owner_id', $user->id);
            })
            ->where('user_id', '!=', $user->id)
            ->with(['document:id,reference,title', 'user:id,name'])
            ->latest()
            ->limit(8)
            ->get(['id', 'document_id', 'user_id', 'content', 'created_at'])
            ->map(fn (Comment $c) => [
                'id' => $c->id,
                'content' => $c->content,
                'created_at' => $c->created_at,
                'user' => $c->user ? ['id' => $c->user->id, 'name' => $c->user->name] : null,
                'document' => $c->document ? [
                    'id' => $c->document->id,
                    'reference' => $c->document->reference,
                    'title' => $c->document->title,
                ] : null,
            ])
            ->all();
    }

    /** @param  \Illuminate\Support\Collection<int, Validation>  $validations */
    private function serializeValidations($validations): array
    {
        return $validations->map(fn (Validation $v) => [
            'id' => $v->id,
            'status' => $v->status?->value ?? $v->status,
            'created_at' => $v->created_at,
            'document' => $v->document ? [
                'id' => $v->document->id,
                'reference' => $v->document->reference,
                'title' => $v->document->title,
                'status' => $v->document->status?->value ?? $v->document->status,
            ] : null,
            'workflow_step' => $v->workflowStep ? [
                'id' => $v->workflowStep->id,
                'name' => $v->workflowStep->name,
                'step_order' => $v->workflowStep->step_order,
            ] : null,
            'due_at' => $v->due_at,
            'sla_hours' => $v->sla_hours,
            'is_overdue' => $v->due_at !== null && $v->due_at->isPast(),
        ])->values()->all();
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
        $query = Validation::query()
            ->where('status', ValidationStatus::Pending->value)
            ->whereHas('document', fn (Builder $q) => $q->where('status', DocumentStatus::InValidation))
            ->currentStep();

        if ($scope->isGlobal()) {
            return $query;
        }

        return $query->whereHas('document', function (Builder $q) use ($scope) {
            $this->applyResourceScope($q, $scope);
        });
    }
}
