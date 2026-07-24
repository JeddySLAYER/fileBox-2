<?php

namespace App\Http\Requests\Document;

use Illuminate\Foundation\Http\FormRequest;

class MoveDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'folder_id' => ['required', 'integer', 'exists:folders,id'],
        ];
    }
}
