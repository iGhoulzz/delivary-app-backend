<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use App\Support\Resolvers\PublicIdResolver;
use Illuminate\Foundation\Http\FormRequest;

final class AdminAssignOrderRequest extends FormRequest
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
            'driver_public_id' => ['required', 'string', 'exists:users,public_id'],
            'force' => ['sometimes', 'boolean'],
        ];
    }

    public function driverUserId(): int
    {
        return PublicIdResolver::userId($this->string('driver_public_id')->toString());
    }
}
