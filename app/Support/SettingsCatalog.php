<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The allowlist of `platform_settings` keys the admin dashboard may edit.
 *
 * This is the single source of truth for which settings are exposed by
 * `GET/PATCH /admin/settings`, their casting `type`, their UI `group`, and any
 * numeric bounds. Keys absent from here are NOT editable through the API.
 *
 * Notes:
 * - Keys mirror exactly what the pricing/payout/settlement code reads
 *   (`pricing.*`, `payouts.*`, `settlement.*`, `new_driver_max_liability`).
 * - `pricing.delivery_fee_base` is intentionally absent — the delivery base fee
 *   is per-region (`regions.base_fee`), not a global platform setting.
 * - `pricing.item_size_modifiers` (json) is intentionally absent — it is
 *   surfaced read-only by the read endpoint and edited via a separate screen.
 */
final class SettingsCatalog
{
    /**
     * @var array<string, array{type: string, group: string, min?: float|int, max?: float|int}>
     */
    private const EDITABLE = [
        'pricing.item_commission_rate'    => ['type' => 'decimal', 'group' => 'pricing', 'min' => 0, 'max' => 1],
        'pricing.driver_fee_cut_rate'     => ['type' => 'decimal', 'group' => 'pricing', 'min' => 0, 'max' => 1],
        'pricing.free_km'                 => ['type' => 'decimal', 'group' => 'pricing', 'min' => 0],
        'pricing.per_km_rate'             => ['type' => 'decimal', 'group' => 'pricing', 'min' => 0],
        'payouts.clearance_hours'         => ['type' => 'integer', 'group' => 'payouts', 'min' => 0],
        'payouts.min_amount'              => ['type' => 'decimal', 'group' => 'payouts', 'min' => 0],
        'payouts.allow_partial'           => ['type' => 'boolean', 'group' => 'payouts'],
        'settlement.reverse_window_hours' => ['type' => 'integer', 'group' => 'settlement', 'min' => 0],
        'new_driver_max_liability'        => ['type' => 'decimal', 'group' => 'risk', 'min' => 0],
    ];

    /** Keys surfaced read-only by the read endpoint (not editable). */
    public const READ_ONLY = ['pricing.item_size_modifiers'];

    /**
     * @return array<string, array{type: string, group: string, min?: float|int, max?: float|int}>
     */
    public static function editable(): array
    {
        return self::EDITABLE;
    }

    public static function has(string $key): bool
    {
        return isset(self::EDITABLE[$key]);
    }

    /**
     * @return array{type: string, group: string, min?: float|int, max?: float|int}|null
     */
    public static function meta(string $key): ?array
    {
        return self::EDITABLE[$key] ?? null;
    }
}
