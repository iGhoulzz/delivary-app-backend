<?php

declare(strict_types=1);

namespace App\Support;

final class OrderStatusLogMetadata
{
    /**
     * Keys explicitly allowed through to the API. Public-id summaries plus the
     * known-safe descriptive keys actually written by the status-log writers
     * in app/Services/Order (see Task 9 enumeration). Extend this list when a
     * new metadata writer adds a key.
     *
     * Note: internal-id-shaped keys (e.g. `region_id`, `inventory_id`) are
     * deliberately NOT allowlisted and are additionally dropped by the
     * defensive `_id` guard in sanitize().
     *
     * @var array<int, string>
     */
    public const ALLOWLIST = [
        // Public-id summaries (replace internal ids in metadata).
        'previous_office_public_id',
        'new_office_public_id',
        'return_office_public_id',
        'driver_public_id',
        'cancelled_by_public_id',
        // Known-safe descriptive keys written by order services.
        'event',
        'reason_note',
        'reason_code',
        'return_reason',
        'return_fault',
        'method',
        'attempt',
        'force',
        'reset_tier',
        'driver_fault',
        'shelf_location',
        'amount',
        'fee_amount',
        'cash_collected',
        'delivery_fee_owed',
        'storage_fee',
        'accrued_storage_fee',
        'waived',
        'distance_km',
        'elapsed_minutes',
        'received_at',
        // Reserved for future writers (per plan Task 9).
        'fault',
        'waiver_amount',
        'bypass_reason',
    ];

    /**
     * Read-time guard, applied recursively and fail-closed. Rules at EVERY depth:
     *
     *  1. Drop any key ending in `_id` unless it ends in `_public_id`
     *     (defensive: a future writer cannot silently leak an internal id,
     *     even nested inside a sub-array).
     *  2. For `*_public_id` keys, keep the value only if it is a string or null;
     *     drop non-string values (public ids are ULID-shaped strings).
     *  3. Recurse into nested arrays, applying rules 1–4 to each level.
     *  4. At the TOP level ONLY, additionally require the key be in ALLOWLIST.
     *     Sub-keys are not allowlisted (we have no per-context sub-key list);
     *     the `_id` guard is what protects nested structures.
     *
     * No DB queries.
     *
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>
     */
    public static function sanitize(?array $metadata): array
    {
        if ($metadata === null) {
            return [];
        }

        return self::sanitizeLevel($metadata, true);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function sanitizeLevel(array $data, bool $topLevel): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            if ($topLevel && is_string($key) && ! in_array($key, self::ALLOWLIST, true)) {
                continue;
            }
            if (is_string($key) && str_ends_with($key, '_id') && ! str_ends_with($key, '_public_id')) {
                continue; // fail-closed on internal-id-shaped keys at any depth
            }
            if (is_string($key) && str_ends_with($key, '_public_id') && $value !== null && ! is_string($value)) {
                continue; // public ids are string|null; drop anything else
            }
            if (is_array($value)) {
                $clean[$key] = self::sanitizeLevel($value, false);

                continue;
            }
            $clean[$key] = $value;
        }

        return $clean;
    }
}
