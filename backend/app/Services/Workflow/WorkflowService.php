<?php

namespace App\Services\Workflow;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Support\DurationHours;
use App\Support\SoftDeleteArchive;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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
            ->withCount($this->usageCounts())
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
        $this->assertNotInUse($workflow, 'modifier');

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
     * @param  array<int, array{name: string, step_order?: int, responsible_role_id?: int|null, responsible_user_id?: int|null, is_mandatory?: bool, description?: string|null, duration_hours?: int|null, duration_amount?: int|null, duration_unit?: string|null, reminder_hours_before?: int|null, reminder_amount?: int|null, reminder_unit?: string|null, remind_on_overdue?: bool}>  $steps
     */
    public function syncSteps(Workflow $workflow, array $steps): Workflow
    {
        $workflow->steps()->delete();

        foreach (array_values($steps) as $index => $step) {
            $order = $step['step_order'] ?? ($index + 1);
            $durationHours = DurationHours::toHours($step['duration_amount'] ?? null, $step['duration_unit'] ?? 'hours')
                ?? (isset($step['duration_hours']) ? (int) $step['duration_hours'] : null);
            $reminderHours = DurationHours::toHours($step['reminder_amount'] ?? null, $step['reminder_unit'] ?? 'hours')
                ?? (isset($step['reminder_hours_before']) ? (int) $step['reminder_hours_before'] : null);

            if ($durationHours !== null && $durationHours < 1) {
                $durationHours = null;
            }
            if ($reminderHours !== null && ($durationHours === null || $reminderHours >= $durationHours)) {
                $reminderHours = null;
            }

            WorkflowStep::query()->create([
                'workflow_id' => $workflow->id,
                'name' => 'Validation '.$order,
                'step_order' => $order,
                'responsible_role_id' => $step['responsible_role_id'] ?? null,
                'responsible_user_id' => $step['responsible_user_id'] ?? null,
                'is_mandatory' => $step['is_mandatory'] ?? true,
                'duration_hours' => $durationHours,
                'reminder_hours_before' => $reminderHours,
                'remind_on_overdue' => true,
                'description' => $step['description'] ?? null,
            ]);
        }

        return $this->loadWorkflow($workflow);
    }

    public function delete(Workflow $workflow): void
    {
        $this->assertNotInUse($workflow, 'supprimer');

        DB::transaction(function () use ($workflow) {
            Document::query()->where('workflow_id', $workflow->id)->update(['workflow_id' => null]);
            DocumentType::query()->where('default_workflow_id', $workflow->id)->update(['default_workflow_id' => null]);
            SoftDeleteArchive::archive($workflow, ['code']);
        });
    }

    private function loadWorkflow(Workflow $workflow): Workflow
    {
        return $workflow->load([
            'steps.responsibleRole',
            'steps.responsibleUser',
            'creator',
        ])->loadCount($this->usageCounts());
    }

    /** @return array<int|string, mixed> */
    public function usageCounts(): array
    {
        return [
            'steps',
            'documents',
            'documents as in_validation_count' => fn (Builder $q) => $q->where('status', DocumentStatus::InValidation),
        ];
    }

    private function assertNotInUse(Workflow $workflow, string $action): void
    {
        if ($workflow->documents()->where('status', DocumentStatus::InValidation)->exists()) {
            throw ValidationException::withMessages([
                'workflow' => ["Impossible de {$action} un workflow utilisé par des documents en cours de validation."],
            ]);
        }
    }

    private function generateCode(string $name): string
    {
        return 'WF-'.Str::upper(Str::slug($name, '-'));
    }
}
