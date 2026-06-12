<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Merchant;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateMerchantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'business_name' => ['sometimes', 'string', 'max:255'],
            'business_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'commission_rate_override' => ['sometimes', 'nullable', 'decimal:0,4', 'min:0', 'max:1'],
            'driver_fee_cut_override' => ['sometimes', 'nullable', 'decimal:0,4', 'min:0', 'max:1'],
            'default_pickup_address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'default_pickup_location' => ['sometimes', 'nullable', 'array'],
            'default_pickup_location.lat' => ['required_with:default_pickup_location', 'numeric', 'between:-90,90'],
            'default_pickup_location.lng' => ['required_with:default_pickup_location', 'numeric', 'between:-180,180'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
