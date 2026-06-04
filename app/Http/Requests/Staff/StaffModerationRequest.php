<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use App\Enums\ModerationReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StaffModerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason_code' => ['sometimes', Rule::enum(ModerationReason::class)],
            'detail' => ['sometimes', 'string', 'min:3', 'max:1000'],
        ];
    }

    public function reason(): ?ModerationReason
    {
        if (! $this->filled('reason_code')) {
            return null;
        }

        return ModerationReason::from($this->string('reason_code')->toString());
    }

    public function detail(): ?string
    {
        if (! $this->filled('detail')) {
            return null;
        }

        return $this->string('detail')->toString();
    }
}
