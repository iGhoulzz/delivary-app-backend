<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

final class DriverLocationUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'heading' => ['nullable', 'numeric', 'between:0,360'],
            'speed_mps' => ['nullable', 'numeric', 'min:0'],
            'accuracy_meters' => ['nullable', 'numeric', 'min:0'],
            'battery_percentage' => ['nullable', 'integer', 'between:0,100'],
        ];
    }
}
