<?php

declare(strict_types=1);

namespace App\Http\Requests\Driver;

use App\Enums\DriverAccountBucket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AdjustAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'bucket' => ['required', Rule::enum(DriverAccountBucket::class)],
            'amount' => ['required', 'numeric'], // signed; negative = debit
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
