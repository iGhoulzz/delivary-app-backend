<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        // identifier is intentionally permissive (string-only) — the
        // controller decides whether it's a phone or email based on
        // `channel`. Strict format validation here would leak whether the
        // identifier is well-formed before the anti-enumeration response is
        // returned.
        return [
            'identifier' => ['required', 'string', 'max:255'],
            'channel' => ['required', 'string', 'in:otp,email'],
        ];
    }
}
