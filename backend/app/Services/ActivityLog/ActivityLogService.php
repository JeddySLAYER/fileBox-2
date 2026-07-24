<?php

namespace App\Services\ActivityLog;

use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\Folder;
use App\Models\Project;
use App\Models\User;
use App\Support\ReportingScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ActivityLogService
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function log(
        string $action,
        ?User $user = null,
        ?Model $subject = null,
        ?string $description = null,
        array $properties = [],
        ?Request $request = null,
    ): ActivityLog {
        $request ??= request();

        return ActivityLog::query()->create([
            'user_id' => $user?->id ?? $request?->user()?->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'properties' => $properties ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }

    /**
     * @param  array{action?: string, user_id?: int, subject_type?: string, search?: string}  $filters
     */
    public function list(User $actor, array $filters = [], int $perPage = 30): LengthAwarePaginator
    {
        $scope = new ReportingScope($actor);

        if (! $scope->canAccess()) {
            throw ValidationException::withMessages([
                'activity' => ['Vous n\'avez pas accès au journal d\'activité.'],
            ]);
        }

        $query = $this->scopedQuery($scope)->with('user');

        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (! empty($filters['subject_type'])) {
            $query->where('subject_type', $filters['subject_type']);
        }

        if (! empty($filters['search'])) {
            $search = mb_strtolower($filters['search']);
            $query->whereRaw('LOWER(description) LIKE ?', ["%{$search}%"]);
        }

        return $query->latest()->paginate($perPage);
    }

    public function scopedQuery(ReportingScope $scope): Builder
    {
        $query = ActivityLog::query();

        if ($scope->isGlobal()) {
            return $query;
        }

        // Masquer journaux techniques / auth hors vue globale
        $query->where('action', 'not like', 'settings.%')
            ->where('action', 'not like', 'backup.%')
            ->where('action', 'not like', 'auth.%');

        if ($scope->mode() === ReportingScope::MODE_DEPARTMENT) {
            $ids = $scope->departmentIds() ?: [0];

            return $query->where(function (Builder $q) use ($ids) {
                $q->whereHasMorph('subject', [Document::class], fn (Builder $s) => $s->whereIn('department_id', $ids))
                    ->orWhereHasMorph('subject', [Folder::class], fn (Builder $s) => $s->whereIn('department_id', $ids))
                    ->orWhereHasMorph('subject', [Project::class], fn (Builder $s) => $s->whereIn('department_id', $ids))
                    ->orWhereHas('user', fn (Builder $u) => $u->whereIn('department_id', $ids));
            });
        }

        $projectIds = $scope->projectIds() ?: [0];

        return $query->where(function (Builder $q) use ($projectIds) {
            $q->whereHasMorph('subject', [Document::class], fn (Builder $s) => $s->whereIn('project_id', $projectIds))
                ->orWhereHasMorph('subject', [Folder::class], fn (Builder $s) => $s->whereIn('project_id', $projectIds))
                ->orWhereHasMorph('subject', [Project::class], fn (Builder $s) => $s->whereIn('id', $projectIds));
        });
    }
}
