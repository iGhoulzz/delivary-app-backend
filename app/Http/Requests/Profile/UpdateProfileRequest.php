<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'first_name' => ['sometimes', 'string', 'min:1', 'max:100'],
            'last_name' => ['sometimes', 'nullable', 'string', 'min:1', 'max:100'],
            'locale' => ['sometimes', 'string', 'in:ar,en'],
            'email' => [
                'sometimes', 'nullable', 'email:rfc', 'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
        ];
    }
}
