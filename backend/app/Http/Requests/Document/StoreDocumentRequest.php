<?php

namespace App\Http\Requests\Document;

use App\Enums\ConfidentialityLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'folder_id' => ['required', 'integer', 'exists:folders,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'document_type_id' => ['nullable', 'integer', 'exists:document_types,id'],
            'workflow_id' => ['nullable', 'integer', 'exists:workflows,id'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'confidentiality' => ['sometimes', Rule::enum(ConfidentialityLevel::class)],
            'is_editable' => ['sometimes', 'boolean'],
            'language' => ['nullable', 'string', 'max:10'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer', 'exists:tags,id'],
            'file' => ['required', 'file', 'max:51200'],
        ];
    }
}
