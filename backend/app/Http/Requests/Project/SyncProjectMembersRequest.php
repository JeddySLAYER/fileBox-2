<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class SyncProjectMembersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'member_ids' => ['required', 'array'],
            'member_ids.*' => ['integer', 'exists:users,id'],
        ];
    }
}
