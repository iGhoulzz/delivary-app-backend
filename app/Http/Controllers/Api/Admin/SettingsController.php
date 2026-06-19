<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Models\PlatformSetting;
use App\Support\SettingsCatalog;
use Illuminate\Http\JsonResponse;

/**
 * Read/update the editable `platform_settings` allowlist (see SettingsCatalog).
 * Edits affect NEW quotes only — historical orders keep their snapshot.
 */
final class SettingsController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $adminId = (int) $request->user()->id;

        foreach ($request->validated()['settings'] as $row) {
            $meta = SettingsCatalog::meta($row['key']);
            // Guaranteed non-null: the request rejects keys outside the catalog.
            PlatformSetting::set($row['key'], $this->castValue($row['value'], $meta['type']), $adminId, $meta['type']);
        }

        return response()->json($this->payload());
    }

    private function castValue(mixed $value, string $type): string|int
    {
        return match ($type) {
            'integer' => (int) $value,
            'boolean' => (bool) $value ? '1' : '0',
            default => (string) $value, // decimal stored as a numeric string
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $grouped = ['pricing' => [], 'payouts' => [], 'settlement' => [], 'risk' => []];

        foreach (SettingsCatalog::editable() as $key => $meta) {
            $grouped[$meta['group']][] = [
                'key' => $key,
                'value' => PlatformSetting::get($key),
                'type' => $meta['type'],
                'min' => $meta['min'] ?? null,
                'max' => $meta['max'] ?? null,
            ];
        }

        return array_merge($grouped, [
            'read_only' => array_map(static fn (string $key): array => [
                'key' => $key,
                'value' => PlatformSetting::get($key),
            ], SettingsCatalog::READ_ONLY),
        ]);
    }
}
