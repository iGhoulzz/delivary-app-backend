<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

final class AttachOfficeAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'office_id' => ['required', 'integer', 'exists:office_locations,id'],
            'is_manager' => ['sometimes', 'boolean'],
        ];
    }

    public function isManager(): bool
    {
        return (bool) $this->boolean('is_manager', false);
    }
}
