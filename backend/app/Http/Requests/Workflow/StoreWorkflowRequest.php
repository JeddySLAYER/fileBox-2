<?php

namespace App\Http\Requests\Workflow;

use Illuminate\Foundation\Http\FormRequest;

class StoreWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:100', 'unique:workflows,code'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'steps' => ['sometimes', 'array', 'min:1'],
            'steps.*.name' => ['required_with:steps', 'string', 'max:255'],
            'steps.*.step_order' => ['sometimes', 'integer', 'min:1'],
            'steps.*.responsible_role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'steps.*.responsible_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'steps.*.is_mandatory' => ['sometimes', 'boolean'],
            'steps.*.description' => ['nullable', 'string'],
        ];
    }
}
