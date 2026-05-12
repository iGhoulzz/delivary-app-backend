<?php

declare(strict_types=1);

namespace App\Http\Requests\Driver;

use Illuminate\Foundation\Http\FormRequest;

final class LookupDriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('office_staff') ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'phone_number' => ['required', 'string', 'regex:/^\+218\d{9}$/'],
        ];
    }
}
