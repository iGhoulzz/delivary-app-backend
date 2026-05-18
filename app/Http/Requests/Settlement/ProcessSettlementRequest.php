<?php

declare(strict_types=1);

namespace App\Http\Requests\Settlement;

use Illuminate\Foundation\Http\FormRequest;

final class ProcessSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'driver_public_id' => ['required', 'string', 'size:26'],
            'cash_received_from_driver' => ['required', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],
            'cash_paid_to_driver' => ['required', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function driverPublicId(): string
    {
        return (string) $this->input('driver_public_id');
    }

    public function cashReceived(): string
    {
        return (string) $this->input('cash_received_from_driver');
    }

    public function cashPaid(): string
    {
        return (string) $this->input('cash_paid_to_driver');
    }
}
