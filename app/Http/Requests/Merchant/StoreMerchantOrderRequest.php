<?php

declare(strict_types=1);

namespace App\Http\Requests\Merchant;

use App\Enums\ItemSize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreMerchantOrderRequest extends FormRequest
{
    /**
     * Mirrors CreateOrderRequest::authorize() — /api/orders has no phone-verified
     * middleware; the gate lives here (phone verification + account eligibility).
     * The route's active.merchant middleware only checks merchant role/status.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->phone_verified_at !== null
            && $user->canCreateOrders();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'quote_token' => ['required', 'string'],

            // Pickup is all-or-nothing; omit entirely to use the profile default.
            'pickup_address' => ['required_with:pickup_location', 'nullable', 'string', 'max:500'],
            'pickup_location' => ['required_with:pickup_address', 'nullable', 'array'],
            'pickup_location.lat' => ['required_with:pickup_location', 'numeric', 'between:-90,90'],
            'pickup_location.lng' => ['required_with:pickup_location', 'numeric', 'between:-180,180'],
            'pickup_notes' => ['nullable', 'string', 'max:500'],

            'receiver_location' => ['required', 'array'],
            'receiver_location.lat' => ['required', 'numeric', 'between:-90,90'],
            'receiver_location.lng' => ['required', 'numeric', 'between:-180,180'],
            'receiver_address' => ['required', 'string', 'max:500'],
            'receiver_phone' => ['required', 'string', 'regex:/^\+218\d{9}$/'],
            'receiver_name' => ['required', 'string', 'max:200'],
            'receiver_notes' => ['nullable', 'string', 'max:500'],

            'item_size' => ['required', Rule::enum(ItemSize::class)],
            'item_description' => ['required', 'string', 'min:5', 'max:500'],
            'item_weight_kg' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'item_value' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'item_price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
        ];
    }
}
