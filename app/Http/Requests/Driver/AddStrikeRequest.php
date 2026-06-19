<?php

declare(strict_types=1);

namespace App\Http\Requests\Driver;

use App\Enums\DriverStrikeReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AddStrikeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::enum(DriverStrikeReason::class)],
            'fee' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
