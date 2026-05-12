<?php

declare(strict_types=1);

namespace App\Http\Requests\Driver;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateRegionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->hasRole('driver');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'region_ids' => ['present', 'array'],
            'region_ids.*' => ['integer', 'exists:regions,id'],
        ];
    }
}
