<?php

declare(strict_types=1);

namespace App\Http\Requests\Settlement;

use App\Support\Resolvers\PublicIdResolver;
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
            'office_public_id' => ['nullable', 'string', 'exists:office_locations,public_id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function officeId(): ?int
    {
        return PublicIdResolver::officeId($this->input('office_public_id'));
    }
}
