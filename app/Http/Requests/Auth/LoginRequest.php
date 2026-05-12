<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        // Validation here is deliberately permissive (just "is something
        // sent"). Stricter format checks at login would leak whether a phone
        // is well-formed before credentials are even tried — `regex:` would
        // give an attacker a free oracle. The format is only enforced at
        // registration; at login, anything that doesn't match is just
        // "invalid_credentials" via the password-check branch.
        return [
            'phone_number' => ['required', 'string', 'max:32'],
            'password' => ['required', 'string', 'min:1', 'max:100'],
        ];
    }
}
