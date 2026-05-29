<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use App\Support\DTO\UpdateStaffInput;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $staffId = $this->route('staff')?->id;

        return [
            'first_name' => ['sometimes', 'string', 'max:60'],
            'last_name' => ['sometimes', 'string', 'max:60'],
            'email' => ['sometimes', 'nullable', 'email', 'max:120', 'unique:users,email,'.$staffId],
        ];
    }

    public function toInput(): UpdateStaffInput
    {
        return new UpdateStaffInput(
            firstName: $this->input('first_name'),
            lastName: $this->input('last_name'),
            email: $this->input('email'),
        );
    }
}
