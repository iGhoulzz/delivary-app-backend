<?php

declare(strict_types=1);

namespace App\Http\Requests\Merchant;

use App\Enums\ItemSize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class QuoteMerchantOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // covered by route middleware (sanctum + active.merchant)
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Pickup is all-or-nothing; omit entirely to use the profile default.
            'pickup_address' => ['required_with:pickup_location', 'nullable', 'string', 'max:500'],
            'pickup_location' => ['required_with:pickup_address', 'nullable', 'array'],
            'pickup_location.lat' => ['required_with:pickup_location', 'numeric', 'between:-90,90'],
            'pickup_location.lng' => ['required_with:pickup_location', 'numeric', 'between:-180,180'],

            'receiver_location' => ['required', 'array'],
            'receiver_location.lat' => ['required', 'numeric', 'between:-90,90'],
            'receiver_location.lng' => ['required', 'numeric', 'between:-180,180'],

            'item_size' => ['required', Rule::enum(ItemSize::class)],
            'item_price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
        ];
    }
}
