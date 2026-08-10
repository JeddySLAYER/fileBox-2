<?php

namespace App\Http\Requests\Project;

use App\Services\Project\ProjectService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Responsable : ne peut pas changer les départements du projet
        if ($this->user()?->isDepartmentScopedProjectManager()) {
            $this->request->remove('department_ids');
            $this->request->remove('department_id');
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $projectId = $this->route('project')?->id ?? $this->route('project');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:50', Rule::unique('projects', 'code')->ignore($projectId)],
            'description' => ['nullable', 'string'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'department_ids' => ['sometimes', 'array', 'min:1'],
            'department_ids.*' => ['integer', 'exists:departments,id'],
            'manager_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['sometimes', 'string', Rule::in(ProjectService::STATUSES)],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'member_ids' => ['sometimes', 'array'],
            'member_ids.*' => ['integer', 'exists:users,id'],
        ];
    }
}
