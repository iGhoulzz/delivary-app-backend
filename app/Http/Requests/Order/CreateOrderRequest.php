<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use App\Enums\ItemSize;
use App\Enums\OrderType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class CreateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->phone_verified_at !== null
            && $user->canCreateOrders();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'quote_token' => ['required', 'string'],
            'order_type' => ['required', Rule::in([OrderType::StandardDelivery->value, OrderType::P2pSale->value])],
            'pickup_location' => ['required', 'array'],
            'pickup_location.lat' => ['required', 'numeric', 'between:-90,90'],
            'pickup_location.lng' => ['required', 'numeric', 'between:-180,180'],
            'pickup_address' => ['required', 'string', 'max:500'],
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
            'item_price' => [
                Rule::requiredIf(fn (): bool => $this->input('order_type') === OrderType::P2pSale->value),
                'numeric',
                'min:0.01',
                'max:99999999.99',
            ],
            'delivery_fee_payer' => ['sometimes', Rule::in(['sender', 'receiver'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('order_type') === OrderType::StandardDelivery->value && $this->filled('item_price')) {
                $validator->errors()->add('item_price', 'item_price is not allowed for standard_delivery orders.');
            }
        });
    }
}
