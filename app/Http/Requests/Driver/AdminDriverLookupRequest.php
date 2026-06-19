<?php

declare(strict_types=1);

namespace App\Http\Requests\Driver;

use Illuminate\Foundation\Http\FormRequest;

final class AdminDriverLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phone_number' => ['required', 'string', 'regex:/^\+218\d{9}$/'],
            'office_public_id' => ['sometimes', 'string', 'exists:office_locations,public_id'],
        ];
    }
}
