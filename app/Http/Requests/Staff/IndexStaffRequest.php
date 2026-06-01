<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use App\Support\Resolvers\PublicIdResolver;
use Illuminate\Foundation\Http\FormRequest;

final class IndexStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'role' => ['sometimes', 'string'],
            'account_status' => ['sometimes', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'office_public_id' => ['sometimes', 'string', 'exists:office_locations,public_id'],
        ];
    }

    public function officeId(): ?int
    {
        return PublicIdResolver::officeId($this->input('office_public_id'));
    }
}
