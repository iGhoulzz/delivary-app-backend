<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Enums\OtpPurpose;
use App\Models\PlatformSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class VerifyOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $codeLength = (int) PlatformSetting::get('otp_code_length', 6);

        return [
            'phone_number' => ['required', 'string', 'regex:/^\+218\d{9}$/'],
            'code' => ['required', 'string', "size:{$codeLength}", 'regex:/^\d+$/'],
            'purpose' => ['required', Rule::enum(OtpPurpose::class)],
        ];
    }

    public function purpose(): OtpPurpose
    {
        return OtpPurpose::from((string) $this->input('purpose'));
    }
}
