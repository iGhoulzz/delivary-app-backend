<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use App\Support\Resolvers\PublicIdResolver;
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
            'office_public_id' => ['required', 'string', 'exists:office_locations,public_id'],
            'is_manager' => ['sometimes', 'boolean'],
        ];
    }

    public function officeId(): int
    {
        return PublicIdResolver::officeId($this->string('office_public_id')->toString());
    }

    public function isManager(): bool
    {
        return (bool) $this->boolean('is_manager', false);
    }
}
