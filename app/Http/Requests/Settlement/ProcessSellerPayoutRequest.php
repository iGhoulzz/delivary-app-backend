<?php

declare(strict_types=1);

namespace App\Http\Requests\Settlement;

use Illuminate\Foundation\Http\FormRequest;

final class ProcessSellerPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'seller_public_id' => ['required', 'string', 'size:26'],
            'earning_public_ids' => ['required', 'array', 'min:1'],
            'earning_public_ids.*' => ['required', 'string', 'size:26', 'distinct'],
            'total_amount' => ['required', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function sellerPublicId(): string
    {
        return (string) $this->input('seller_public_id');
    }

    /**
     * @return array<int, string>
     */
    public function earningPublicIds(): array
    {
        return array_values(array_map('strval', (array) $this->input('earning_public_ids', [])));
    }

    public function totalAmount(): string
    {
        return (string) $this->input('total_amount');
    }
}
