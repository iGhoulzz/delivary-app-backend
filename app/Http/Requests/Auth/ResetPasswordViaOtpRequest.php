<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Models\PlatformSetting;
use Illuminate\Foundation\Http\FormRequest;

final class ResetPasswordViaOtpRequest extends FormRequest
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
            // reset_token is the 64-char Str::random string minted by
            // OtpController::verify() with purpose=password_reset.
            'reset_token' => ['required', 'string', 'size:64'],
            'new_password' => ['required', 'string', "min:{$minLen}", 'max:100'],
        ];
    }
}
