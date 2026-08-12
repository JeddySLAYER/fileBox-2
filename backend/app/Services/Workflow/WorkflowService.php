<?php

namespace App\Services\Workflow;

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Support\SoftDeleteArchive;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WorkflowService
{
    /**
     * @param  array{search?: string, is_active?: bool|string|null}  $filters
     */
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Workflow::query()
            ->with(['steps.responsibleRole', 'steps.responsibleUser', 'creator'])
            ->withCount(['steps', 'documents'])
            ->orderBy('name');

        if (! empty($filters['search'])) {
            $search = mb_strtolower($filters['search']);
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(code) LIKE ?', ["%{$search}%"]);
            });
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null && $filters['is_active'] !== '') {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        return $query->paginate($perPage);
    }

    /**
     * @param  array{name: string, code?: string, description?: string|null, is_active?: bool, steps?: array<int, array<string, mixed>>}  $data
     */
    public function create(User $actor, array $data): Workflow
    {
        return DB::transaction(function () use ($actor, $data) {
            $workflow = Workflow::query()->create([
                'name' => $data['name'],
                'code' => $data['code'] ?? $this->generateCode($data['name']),
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'created_by' => $actor->id,
            ]);

            if (! empty($data['steps'])) {
                $this->syncSteps($workflow, $data['steps']);
            }

            return $this->loadWorkflow($workflow);
        });
    }

    /**
     * @param  array{name?: string, code?: string, description?: string|null, is_active?: bool, steps?: array<int, array<string, mixed>>}  $data
     */
    public function update(Workflow $workflow, array $data): Workflow
    {
        return DB::transaction(function () use ($workflow, $data) {
            $workflow->fill(collect($data)->only([
                'name',
                'code',
                'description',
                'is_active',
            ])->all());
            $workflow->save();

            if (array_key_exists('steps', $data)) {
                $this->syncSteps($workflow, $data['steps'] ?? []);
            }

            return $this->loadWorkflow($workflow);
        });
    }

    /**
     * @param  array<int, array{name: string, step_order?: int, responsible_role_id?: int|null, responsible_user_id?: int|null, is_mandatory?: bool, description?: string|null}>  $steps
     */
    public function syncSteps(Workflow $workflow, array $steps): Workflow
    {
        $workflow->steps()->delete();

        foreach (array_values($steps) as $index => $step) {
            $order = $step['step_order'] ?? ($index + 1);

            WorkflowStep::query()->create([
                'workflow_id' => $workflow->id,
                'name' => 'Validation '.$order,
                'step_order' => $order,
                'responsible_role_id' => $step['responsible_role_id'] ?? null,
                'responsible_user_id' => $step['responsible_user_id'] ?? null,
                'is_mandatory' => $step['is_mandatory'] ?? true,
                'description' => $step['description'] ?? null,
            ]);
        }

        return $this->loadWorkflow($workflow);
    }

    public function delete(Workflow $workflow): void
    {
        if ($workflow->documents()->whereIn('status', ['en_validation'])->exists()) {
            throw ValidationException::withMessages([
                'workflow' => ['Impossible de supprimer un workflow utilisé par des documents en cours de validation.'],
            ]);
        }

        SoftDeleteArchive::archive($workflow, ['code']);
    }

    private function loadWorkflow(Workflow $workflow): Workflow
    {
        return $workflow->load([
            'steps.responsibleRole',
            'steps.responsibleUser',
            'creator',
        ])->loadCount(['steps', 'documents']);
    }

    private function generateCode(string $name): string
    {
        return 'WF-'.Str::upper(Str::slug($name, '-'));
    }
}
