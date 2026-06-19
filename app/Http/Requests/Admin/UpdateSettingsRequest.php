<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\SettingsCatalog;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateSettingsRequest extends FormRequest
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
            'settings' => ['required', 'array', 'min:1'],
            'settings.*.key' => ['required', 'string'],
            'settings.*.value' => ['required'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var array<int, array<string, mixed>> $rows */
            $rows = (array) $this->input('settings', []);

            foreach ($rows as $i => $row) {
                $key = is_array($row) ? ($row['key'] ?? null) : null;
                $meta = is_string($key) ? SettingsCatalog::meta($key) : null;

                if ($meta === null) {
                    $validator->errors()->add("settings.$i.key", "Setting '".(is_string($key) ? $key : '')."' is not editable.");

                    continue;
                }

                $this->validateValue($validator, (int) $i, $row['value'] ?? null, $meta);
            }
        });
    }

    /**
     * @param  array{type: string, group: string, min?: float|int, max?: float|int}  $meta
     */
    private function validateValue(Validator $validator, int $i, mixed $value, array $meta): void
    {
        if ($meta['type'] === 'boolean') {
            if (! is_bool($value) && ! in_array($value, [0, 1, '0', '1'], true)) {
                $validator->errors()->add("settings.$i.value", 'Value must be a boolean.');
            }

            return;
        }

        if (! is_numeric($value)) {
            $validator->errors()->add("settings.$i.value", 'Value must be numeric.');

            return;
        }

        $number = (float) $value;

        if (isset($meta['min']) && $number < $meta['min']) {
            $validator->errors()->add("settings.$i.value", "Value is below the minimum of {$meta['min']}.");
        }

        if (isset($meta['max']) && $number > $meta['max']) {
            $validator->errors()->add("settings.$i.value", "Value is above the maximum of {$meta['max']}.");
        }
    }
}
