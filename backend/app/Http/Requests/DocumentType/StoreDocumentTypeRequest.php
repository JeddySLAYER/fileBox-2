<?php

namespace App\Http\Requests\DocumentType;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreDocumentTypeRequest extends FormRequest
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
            'slug' => ['sometimes', 'string', 'max:255', 'unique:document_types,slug'],
            'description' => ['nullable', 'string'],
            'default_workflow_id' => ['nullable', 'integer', 'exists:workflows,id'],
            'requires_workflow' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->boolean('requires_workflow') && ! $this->input('default_workflow_id')) {
                $validator->errors()->add(
                    'default_workflow_id',
                    'Choisissez un workflow par défaut : ce type exige un circuit de validation.',
                );
            }
        });
    }
}
