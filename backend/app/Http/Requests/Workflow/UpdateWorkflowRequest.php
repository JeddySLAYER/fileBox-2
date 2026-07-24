<?php

namespace App\Http\Requests\Workflow;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'steps' => ['sometimes', 'array'],
            'steps.*.name' => ['required_with:steps', 'string', 'max:255'],
            'steps.*.step_order' => ['sometimes', 'integer', 'min:1'],
            'steps.*.responsible_role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'steps.*.responsible_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'steps.*.is_mandatory' => ['sometimes', 'boolean'],
            'steps.*.description' => ['nullable', 'string'],
        ];
    }
}
