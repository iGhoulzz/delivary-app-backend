<?php

declare(strict_types=1);

namespace App\Http\Requests\Driver;

use App\Enums\VehicleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AdminOnboardDriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::in(['existing', 'new'])],
            'office_public_id' => ['required', 'string', 'exists:office_locations,public_id'],
            'user_public_id' => ['exclude_unless:mode,existing', 'required', 'string', 'exists:users,public_id'],
            'phone_number' => [
                'exclude_unless:mode,new',
                'required',
                'string',
                'regex:/^\+218\d{9}$/',
                Rule::unique('users', 'phone_number'),
            ],
            'first_name' => ['exclude_unless:mode,new', 'required', 'string', 'min:1', 'max:100'],
            'last_name' => ['nullable', 'string', 'min:1', 'max:100'],
            'vehicle_type' => ['required', Rule::enum(VehicleType::class)],
            'vehicle_plate' => ['required', 'string', 'min:1', 'max:32'],
            'vehicle_color' => ['nullable', 'string', 'min:1', 'max:32'],
            'vehicle_model' => ['nullable', 'string', 'min:1', 'max:64'],
        ];
    }
}
