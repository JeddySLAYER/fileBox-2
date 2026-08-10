<?php

namespace App\Http\Requests\Validation;

use Illuminate\Foundation\Http\FormRequest;

class RejectValidationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'comment' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'comment.required' => 'Un commentaire est obligatoire pour rejeter un document.',
            'comment.min' => 'Le motif de rejet doit contenir au moins 3 caractères.',
        ];
    }
}
