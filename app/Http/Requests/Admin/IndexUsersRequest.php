<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\AccountStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:120'],
            'account_status' => ['sometimes', Rule::enum(AccountStatus::class)],
            'role' => ['sometimes', 'string', 'max:64'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
