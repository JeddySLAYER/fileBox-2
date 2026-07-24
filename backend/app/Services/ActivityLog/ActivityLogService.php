<?php

namespace App\Services\ActivityLog;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

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
    public function list(array $filters = [], int $perPage = 30): LengthAwarePaginator
    {
        $query = ActivityLog::query()
            ->with('user')
            ->latest();

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

        return $query->paginate($perPage);
    }

    /**
     * Dernières lignes du journal technique Laravel.
     *
     * @return list<string>
     */
    public function tailSystemLog(int $lines = 100): array
    {
        $path = storage_path('logs/laravel.log');
        if (! is_file($path)) {
            return [];
        }

        // ponytail: read whole file for small logs; upgrade: seek from end for multi-GB
        $content = file($path, FILE_IGNORE_NEW_LINES);
        if ($content === false) {
            return [];
        }

        return array_values(array_slice($content, -$lines));
    }
}
