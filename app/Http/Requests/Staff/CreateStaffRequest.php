<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use App\Support\DTO\CreateStaffInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phone_number' => ['required', 'string', 'regex:/^\+218[0-9]{9}$/', 'unique:users,phone_number'],
            'first_name' => ['required', 'string', 'max:60'],
            'last_name' => ['required', 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:120', 'unique:users,email'],
            'role' => ['required', Rule::in(['admin', 'office_staff'])],
            'office_assignments' => [
                'required_if:role,office_staff',
                'prohibited_if:role,admin',
                'array',
                'min:1',
            ],
            'office_assignments.*.office_id' => ['required', 'integer', 'exists:office_locations,id'],
            'office_assignments.*.is_manager' => ['required', 'boolean'],
        ];
    }

    public function toInput(): CreateStaffInput
    {
        return new CreateStaffInput(
            phoneNumber: $this->string('phone_number')->toString(),
            firstName: $this->string('first_name')->toString(),
            lastName: $this->string('last_name')->toString(),
            email: $this->input('email'),
            role: $this->string('role')->toString(),
            officeAssignments: $this->input('office_assignments', []),
        );
    }
}
