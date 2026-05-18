<?php

declare(strict_types=1);

namespace App\Http\Requests\Settlement;

use Illuminate\Foundation\Http\FormRequest;

final class LookupSellerPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone' => ['required_without:public_id', 'nullable', 'string', 'max:20'],
            'public_id' => ['required_without:phone', 'nullable', 'string', 'size:26'],
        ];
    }
}
