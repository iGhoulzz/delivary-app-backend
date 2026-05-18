<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

final class AdminUnassignOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['sometimes', 'string', 'max:500'],
            'reset_tier' => ['sometimes', 'boolean'],
            'driver_fault' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'string', 'max:1000'],
            'fee_amount_override' => ['sometimes', 'numeric', 'min:0', 'max:999999.99'],
        ];
    }
}
