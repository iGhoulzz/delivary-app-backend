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
     * Read-time guard: keep allowlisted keys; drop everything else, and
     * defensively drop ANY remaining key ending in `_id` (fail-closed) so a
     * future writer cannot silently leak an internal id. No DB queries.
     *
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>
     */
    public static function sanitize(?array $metadata): array
    {
        if ($metadata === null) {
            return [];
        }

        $clean = [];
        foreach ($metadata as $key => $value) {
            if (! in_array($key, self::ALLOWLIST, true)) {
                continue;
            }
            if (str_ends_with($key, '_id') && ! str_ends_with($key, '_public_id')) {
                continue; // fail-closed on internal-id-shaped keys
            }
            $clean[$key] = $value;
        }

        return $clean;
    }
}
