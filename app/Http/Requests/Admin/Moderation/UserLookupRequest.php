<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Moderation;

use Illuminate\Foundation\Http\FormRequest;

final class UserLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
        ];
    }

    public function phone(): string
    {
        return $this->string('phone')->toString();
    }
}
