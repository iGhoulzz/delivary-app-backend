<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Models\PlatformSetting;
use Illuminate\Foundation\Http\FormRequest;

final class ResetPasswordViaEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        $minLen = (int) PlatformSetting::get('password_min_length', 8);

        return [
            // signed_token is the base64 payload from the email link's `token`
            // query param. Its length varies but is always > 64 chars.
            'signed_token' => ['required', 'string', 'min:64', 'max:1024'],
            'new_password' => ['required', 'string', "min:{$minLen}", 'max:100'],
        ];
    }
}
