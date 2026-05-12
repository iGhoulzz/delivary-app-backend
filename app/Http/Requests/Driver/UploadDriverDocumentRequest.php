<?php

declare(strict_types=1);

namespace App\Http\Requests\Driver;

use App\Enums\DriverDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UploadDriverDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('office_staff') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(DriverDocumentType::class)],
            'file' => ['required', 'file', 'mimes:jpeg,jpg,png,webp,pdf', 'max:10240'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
