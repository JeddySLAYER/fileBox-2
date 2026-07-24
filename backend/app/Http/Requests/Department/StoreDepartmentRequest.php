<?php

namespace App\Http\Requests\Department;

use Illuminate\Foundation\Http\FormRequest;

class StoreDepartmentRequest extends FormRequest
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
            'code' => ['sometimes', 'string', 'max:50', 'unique:departments,code'],
            'description' => ['nullable', 'string'],
            'manager_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
