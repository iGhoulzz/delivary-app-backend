<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Merchant;

use Illuminate\Foundation\Http\FormRequest;

final class StoreMerchantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_public_id' => ['required', 'string'],
            'business_name' => ['required', 'string', 'max:255'],
            'business_phone' => ['nullable', 'string', 'max:32'],
            'commission_rate_override' => ['nullable', 'decimal:0,4', 'min:0', 'max:1'],
            'driver_fee_cut_override' => ['nullable', 'decimal:0,4', 'min:0', 'max:1'],
            'default_pickup_address' => ['nullable', 'string', 'max:1000'],
            'default_pickup_location' => ['nullable', 'array'],
            'default_pickup_location.lat' => ['required_with:default_pickup_location', 'numeric', 'between:-90,90'],
            'default_pickup_location.lng' => ['required_with:default_pickup_location', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
