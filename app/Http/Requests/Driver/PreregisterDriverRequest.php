<?php

declare(strict_types=1);

namespace App\Http\Requests\Driver;

use App\Enums\VehicleType;
use App\Support\Resolvers\PublicIdResolver;
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
            'office_public_id' => ['required', 'string', Rule::exists('office_locations', 'public_id')->where('is_active', true)],
            'vehicle_type' => ['required', Rule::enum(VehicleType::class)],
            'vehicle_plate' => ['required', 'string', 'min:1', 'max:32'],
            'vehicle_color' => ['nullable', 'string', 'min:1', 'max:32'],
            'vehicle_model' => ['nullable', 'string', 'min:1', 'max:64'],
        ];
    }

    public function officeId(): int
    {
        return PublicIdResolver::officeId($this->string('office_public_id')->toString());
    }
}
