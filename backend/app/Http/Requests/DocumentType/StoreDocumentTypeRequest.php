<?php

namespace App\Http\Requests\DocumentType;

use Illuminate\Foundation\Http\FormRequest;

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
}
