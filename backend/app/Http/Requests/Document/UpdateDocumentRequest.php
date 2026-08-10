<?php

namespace App\Http\Requests\Document;

use App\Enums\ConfidentialityLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'folder_id' => ['sometimes', 'integer', 'exists:folders,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'document_type_id' => ['nullable', 'integer', 'exists:document_types,id'],
            'workflow_id' => ['nullable', 'integer', 'exists:workflows,id'],
            'owner_id' => ['sometimes', 'integer', 'exists:users,id'],
            'confidentiality' => ['sometimes', Rule::enum(ConfidentialityLevel::class)],
            'language' => ['nullable', 'string', 'max:10'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer', 'exists:tags,id'],
        ];
    }
}
