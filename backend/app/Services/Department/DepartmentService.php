<?php

namespace App\Services\Department;

use App\Models\Department;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class DepartmentService
{
    /**
     * @param  array{search?: string, trashed?: bool}  $filters
     */
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Department::query()
            ->with('manager')
            ->withCount(['users', 'projects'])
            ->orderBy('name');

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

        return $query->paginate($perPage);
    }

    /**
     * @param  array{name: string, code?: string, description?: string|null, manager_id?: int|null}  $data
     */
    public function create(array $data): Department
    {
        return Department::query()->create([
            'name' => $data['name'],
            'code' => $data['code'] ?? Str::upper(Str::slug($data['name'], '_')),
            'description' => $data['description'] ?? null,
            'manager_id' => $data['manager_id'] ?? null,
        ])->load('manager')->loadCount(['users', 'projects']);
    }

    /**
     * @param  array{name?: string, code?: string, description?: string|null, manager_id?: int|null}  $data
     */
    public function update(Department $department, array $data): Department
    {
        $department->fill(collect($data)->only(['name', 'code', 'description', 'manager_id'])->all());
        $department->save();

        return $department->load('manager')->loadCount(['users', 'projects']);
    }

    public function delete(Department $department): void
    {
        $department->delete();
    }

    public function restore(Department $department): Department
    {
        $department->restore();

        return $department->load('manager')->loadCount(['users', 'projects']);
    }
}
