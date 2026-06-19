<?php

declare(strict_types=1);

namespace App\Http\Requests\Driver;

use App\Support\Resolvers\PublicIdResolver;
use Illuminate\Foundation\Http\FormRequest;

final class IndexDriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string'],
            'activity_status' => ['sometimes', 'string'],
            'search' => ['sometimes', 'string', 'max:120'],
            'office_public_id' => ['sometimes', 'string', 'exists:office_locations,public_id'],
        ];
    }

    public function officeId(): ?int
    {
        return PublicIdResolver::officeId($this->input('office_public_id'));
    }
}
