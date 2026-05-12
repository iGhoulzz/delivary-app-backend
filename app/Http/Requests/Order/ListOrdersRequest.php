<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListOrdersRequest extends FormRequest
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
            'role' => ['sometimes', Rule::in(['all', 'sent', 'received'])],
            'status' => ['sometimes', Rule::in(array_merge(['active'], array_column(OrderStatus::cases(), 'value')))],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
