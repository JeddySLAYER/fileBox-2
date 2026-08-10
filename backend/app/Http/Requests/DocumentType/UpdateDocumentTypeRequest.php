<?php

namespace App\Http\Requests\DocumentType;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDocumentTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->route('document_type')?->id ?? $this->route('document_type');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', Rule::unique('document_types', 'slug')->ignore($id)],
            'description' => ['nullable', 'string'],
            'default_workflow_id' => ['nullable', 'integer', 'exists:workflows,id'],
            'requires_workflow' => ['sometimes', 'boolean'],
        ];
    }
}
