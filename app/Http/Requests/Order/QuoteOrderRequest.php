<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use App\Enums\ItemSize;
use App\Enums\OrderType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class QuoteOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // covered by route middleware (sanctum)
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'order_type' => ['required', Rule::in([
                OrderType::StandardDelivery->value,
                OrderType::P2pSale->value,
            ])],
            'pickup_location' => ['required', 'array'],
            'pickup_location.lat' => ['required', 'numeric', 'between:-90,90'],
            'pickup_location.lng' => ['required', 'numeric', 'between:-180,180'],
            'receiver_location' => ['required', 'array'],
            'receiver_location.lat' => ['required', 'numeric', 'between:-90,90'],
            'receiver_location.lng' => ['required', 'numeric', 'between:-180,180'],
            'item_size' => ['required', Rule::enum(ItemSize::class)],
            'item_price' => [
                Rule::requiredIf(fn () => $this->input('order_type') === OrderType::P2pSale->value),
                'numeric',
                'min:0.01',
                'max:99999999.99',
            ],
            'delivery_fee_payer' => ['sometimes', Rule::in(['sender', 'receiver'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v): void {
            if ($this->input('order_type') === OrderType::StandardDelivery->value && $this->filled('item_price')) {
                $v->errors()->add('item_price', 'item_price is not allowed for standard_delivery orders.');
            }
        });
    }

    public function resolvedItemPrice(): string
    {
        $raw = $this->input('item_price', '0');

        return bcadd((string) $raw, '0', 2);
    }

    public function resolvedPayer(): string
    {
        // Spec §4.4: P2P sale = receiver only (forced); standard_delivery defaults to sender.
        if ($this->input('order_type') === OrderType::P2pSale->value) {
            return 'receiver';
        }

        return (string) $this->input('delivery_fee_payer', 'sender');
    }
}
