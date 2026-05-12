<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Enums\OtpPurpose;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RequestOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'phone_number' => ['required', 'string', 'regex:/^\+218\d{9}$/'],
            'purpose' => ['required', Rule::enum(OtpPurpose::class)],
        ];
    }

    public function purpose(): OtpPurpose
    {
        return OtpPurpose::from((string) $this->input('purpose'));
    }
}
