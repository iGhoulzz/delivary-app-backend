<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use App\Support\Resolvers\PublicIdResolver;
use Illuminate\Foundation\Http\FormRequest;

final class RedirectReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'office_public_id' => ['required', 'string', 'exists:office_locations,public_id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function officeId(): int
    {
        return PublicIdResolver::officeId($this->string('office_public_id')->toString());
    }
}
