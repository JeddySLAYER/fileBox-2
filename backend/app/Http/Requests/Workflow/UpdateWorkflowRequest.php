<?php

namespace App\Http\Requests\Workflow;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->route('workflow')?->id ?? $this->route('workflow');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:100', Rule::unique('workflows', 'code')->ignore($id)],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'steps' => ['sometimes', 'array', 'min:1'],
            'steps.*.name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'steps.*.step_order' => ['sometimes', 'integer', 'min:1'],
            'steps.*.responsible_user_id' => ['required_with:steps', 'integer', 'exists:users,id'],
            'steps.*.responsible_role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'steps.*.is_mandatory' => ['sometimes', 'boolean'],
            'steps.*.description' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->has('steps')) {
                return;
            }

            $userIds = collect($this->input('steps', []))
                ->pluck('responsible_user_id')
                ->filter()
                ->map(fn ($id) => (int) $id);

            if ($userIds->count() !== $userIds->unique()->count()) {
                $validator->errors()->add(
                    'steps',
                    'Un utilisateur ne peut être choisi qu’une seule fois dans le workflow.',
                );
            }
        });
    }
}
