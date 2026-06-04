<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Moderation;

use App\Enums\ModerationReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ModerationActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason_code' => ['required', Rule::enum(ModerationReason::class)],
            'detail' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    public function reason(): ModerationReason
    {
        return ModerationReason::from($this->string('reason_code')->toString());
    }

    public function detail(): string
    {
        return $this->string('detail')->toString();
    }
}
