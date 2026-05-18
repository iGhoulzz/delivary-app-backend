<?php

declare(strict_types=1);

namespace App\Http\Requests\Settlement;

use Illuminate\Foundation\Http\FormRequest;

final class ListSellerPayoutsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'seller_public_id' => ['nullable', 'string', 'size:26'],
            'office_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
