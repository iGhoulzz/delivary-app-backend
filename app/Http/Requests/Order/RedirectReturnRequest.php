<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

final class RedirectReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'office_id' => ['required', 'integer', 'exists:office_locations,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
