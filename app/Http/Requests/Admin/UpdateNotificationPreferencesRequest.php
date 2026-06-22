<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'push' => ['sometimes', 'boolean'],
            'sms' => ['sometimes', 'boolean'],
            'email' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->hasAnyPreferenceKey()) {
                return;
            }

            $validator->errors()->add('notification_preferences', 'At least one notification preference must be provided.');
        });
    }

    /** @return array{push?: bool, sms?: bool, email?: bool} */
    public function preferences(): array
    {
        $preferences = [];

        foreach (['push', 'sms', 'email'] as $key) {
            if (array_key_exists($key, $this->all())) {
                $preferences[$key] = $this->boolean($key);
            }
        }

        return $preferences;
    }

    private function hasAnyPreferenceKey(): bool
    {
        foreach (['push', 'sms', 'email'] as $key) {
            if (array_key_exists($key, $this->all())) {
                return true;
            }
        }

        return false;
    }
}
