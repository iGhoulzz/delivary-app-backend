<?php

declare(strict_types=1);

namespace App\Http\Requests\Settlement;

use Illuminate\Foundation\Http\FormRequest;

final class ReverseSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    public function reason(): string
    {
        return (string) $this->input('reason');
    }
}
