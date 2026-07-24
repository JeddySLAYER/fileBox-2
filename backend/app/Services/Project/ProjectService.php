<?php

namespace App\Services\Project;

use App\Models\Project;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProjectService
{
    /**
     * @param  array{search?: string, department_id?: int, status?: string, trashed?: bool}  $filters
     */
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Project::query()
            ->with(['department', 'manager'])
            ->withCount('members')
            ->latest();

        if (! empty($filters['trashed'])) {
            $query->onlyTrashed();
        }

        if (! empty($filters['search'])) {
            $search = mb_strtolower($filters['search']);
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(code) LIKE ?', ["%{$search}%"]);
            });
        }

        if (! empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($perPage);
    }

    /**
     * @param  array{name: string, code?: string, description?: string|null, department_id?: int|null, manager_id?: int|null, status?: string, member_ids?: array<int>}  $data
     */
    public function create(array $data): Project
    {
        return DB::transaction(function () use ($data) {
            $project = Project::query()->create([
                'name' => $data['name'],
                'code' => $data['code'] ?? $this->generateCode($data['name']),
                'description' => $data['description'] ?? null,
                'department_id' => $data['department_id'] ?? null,
                'manager_id' => $data['manager_id'] ?? null,
                'status' => $data['status'] ?? 'actif',
            ]);

            if (! empty($data['member_ids'])) {
                $project->members()->sync($data['member_ids']);
            }

            return $project->load(['department', 'manager', 'members'])->loadCount('members');
        });
    }

    /**
     * @param  array{name?: string, code?: string, description?: string|null, department_id?: int|null, manager_id?: int|null, status?: string, member_ids?: array<int>}  $data
     */
    public function update(Project $project, array $data): Project
    {
        return DB::transaction(function () use ($project, $data) {
            $project->fill(collect($data)->only([
                'name',
                'code',
                'description',
                'department_id',
                'manager_id',
                'status',
            ])->all());
            $project->save();

            if (array_key_exists('member_ids', $data)) {
                $project->members()->sync($data['member_ids'] ?? []);
            }

            return $project->load(['department', 'manager', 'members'])->loadCount('members');
        });
    }

    public function delete(Project $project): void
    {
        $project->delete();
    }

    public function restore(Project $project): Project
    {
        $project->restore();

        return $project->load(['department', 'manager', 'members'])->loadCount('members');
    }

    /**
     * @param  array<int>  $memberIds
     */
    public function syncMembers(Project $project, array $memberIds): Project
    {
        $project->members()->sync($memberIds);

        return $project->load(['department', 'manager', 'members'])->loadCount('members');
    }

    private function generateCode(string $name): string
    {
        $slug = Str::upper(Str::slug($name, '-'));

        return 'PRJ-'.$slug.'-'.now()->year;
    }
}
