<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AdminListOrdersRequest extends FormRequest
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
            'status' => ['sometimes', Rule::in(array_column(OrderStatus::cases(), 'value'))],
            'type' => ['sometimes', Rule::in(array_column(OrderType::cases(), 'value'))],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
