<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
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
