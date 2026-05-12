<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Models\PlatformSetting;
use Illuminate\Foundation\Http\FormRequest;

final class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>|string> */
    public function rules(): array
    {
        $minPasswordLength = (int) PlatformSetting::get('password_min_length', 8);

        return [
            // E.164 with Libya prefix (+218 + 9 digits). Loosen to general
            // E.164 if/when the platform expands beyond Libya.
            'phone_number' => [
                'required', 'string',
                'regex:/^\+218\d{9}$/',
                'unique:users,phone_number',
            ],
            'first_name' => ['required', 'string', 'min:1', 'max:100'],
            'last_name' => ['nullable', 'string', 'min:1', 'max:100'],
            'email' => ['nullable', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', "min:{$minPasswordLength}", 'max:100'],
            'locale' => ['nullable', 'string', 'in:ar,en'],
        ];
    }
}
