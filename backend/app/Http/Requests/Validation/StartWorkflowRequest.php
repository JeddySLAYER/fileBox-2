<?php

namespace App\Http\Requests\Validation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'workflow_id' => ['nullable', 'integer', 'exists:workflows,id'],
            'deadlines' => ['nullable', 'array'],
            'deadlines.*.workflow_step_id' => ['required', 'integer', 'exists:workflow_steps,id'],
            'deadlines.*.amount' => ['required', 'integer', 'min:1', 'max:8760'],
            'deadlines.*.unit' => ['required', Rule::in(['hours', 'days'])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'deadlines.*.amount.min' => 'La durée doit être d\'au moins 1.',
            'deadlines.*.unit.in' => 'Unité de délai invalide (hours ou days).',
        ];
    }
}
