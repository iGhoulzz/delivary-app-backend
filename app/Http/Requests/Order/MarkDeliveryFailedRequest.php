<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use App\Enums\ReturnReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class MarkDeliveryFailedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::enum(ReturnReason::class)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
