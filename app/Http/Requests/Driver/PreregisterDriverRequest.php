<?php

declare(strict_types=1);

namespace App\Http\Requests\Driver;

use App\Enums\VehicleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PreregisterDriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->phone_verified_at !== null
            && $user->isActive();
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'office_id' => ['required', 'integer', Rule::exists('office_locations', 'id')->where('is_active', true)],
            'vehicle_type' => ['required', Rule::enum(VehicleType::class)],
            'vehicle_plate' => ['required', 'string', 'min:1', 'max:32'],
            'vehicle_color' => ['nullable', 'string', 'min:1', 'max:32'],
            'vehicle_model' => ['nullable', 'string', 'min:1', 'max:64'],
        ];
    }
}
