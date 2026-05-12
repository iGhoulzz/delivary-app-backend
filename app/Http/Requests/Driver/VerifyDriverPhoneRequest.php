<?php

declare(strict_types=1);

namespace App\Http\Requests\Driver;

use App\Models\PlatformSetting;
use Illuminate\Foundation\Http\FormRequest;

final class VerifyDriverPhoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('office_staff') ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        $codeLength = (int) PlatformSetting::get('otp_code_length', 6);

        return [
            'code' => ['required', 'string', "size:{$codeLength}", 'regex:/^\d+$/'],
        ];
    }
}
