<?php

namespace App\Http\Requests\Access;

use App\Enums\AccessAbility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'abilities' => ['sometimes', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::enum(AccessAbility::class)],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ];
    }
}
