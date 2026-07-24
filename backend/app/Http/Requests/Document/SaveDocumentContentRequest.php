<?php

namespace App\Http\Requests\Document;

use Illuminate\Foundation\Http\FormRequest;

class SaveDocumentContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string'],
            'file_name' => ['sometimes', 'string', 'max:255'],
            'change_summary' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
