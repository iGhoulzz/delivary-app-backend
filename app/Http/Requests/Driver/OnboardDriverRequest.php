<?php

declare(strict_types=1);

namespace App\Http\Requests\Driver;

use App\Enums\VehicleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class OnboardDriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('office_staff') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'phone_number' => ['required', 'string', 'regex:/^\+218\d{9}$/'],
            'first_name' => ['nullable', 'string', 'min:1', 'max:100'],
            'last_name' => ['nullable', 'string', 'min:1', 'max:100'],
            'vehicle_type' => ['required', Rule::enum(VehicleType::class)],
            'vehicle_plate' => ['required', 'string', 'min:1', 'max:32'],
            'vehicle_color' => ['nullable', 'string', 'min:1', 'max:32'],
            'vehicle_model' => ['nullable', 'string', 'min:1', 'max:64'],
        ];
    }
}
