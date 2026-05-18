<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PlatformSetting;
use Illuminate\Database\Seeder;

final class OrderLifecyclePlatformSettingsSeeder extends Seeder
{
    /**
     * Idempotent — uses firstOrNew internally via PlatformSetting::set.
     * Day-1 defaults match the spec's "flat fee per region" baseline.
     */
    public function run(): void
    {
        $defaults = [
            // Pricing
            ['key' => 'pricing.item_size_modifiers', 'type' => 'json', 'value' => ['small' => 0, 'medium' => 0, 'large' => 0, 'xlarge' => 0], 'description' => 'LYD additions by item size, on top of region.base_fee'],
            ['key' => 'pricing.free_km', 'type' => 'decimal', 'value' => 999, 'description' => 'Distance below this is free; effectively disables per-km pricing at default'],
            ['key' => 'pricing.per_km_rate', 'type' => 'decimal', 'value' => 0, 'description' => 'LYD per km charged beyond free_km'],
            ['key' => 'pricing.item_commission_rate', 'type' => 'decimal', 'value' => 0.00, 'description' => 'P2P sale platform commission rate (0 at launch)'],
            ['key' => 'pricing.driver_fee_cut_rate', 'type' => 'decimal', 'value' => 0.02, 'description' => 'Platform cut of delivery fee (2% per spec §4.2)'],

            // Broadcast / escalation
            ['key' => 'broadcast.tier_1_radius_km', 'type' => 'decimal', 'value' => 3],
            ['key' => 'broadcast.tier_2_radius_km', 'type' => 'decimal', 'value' => 5],
            ['key' => 'broadcast.tier_3_radius_km', 'type' => 'decimal', 'value' => 10],
            ['key' => 'broadcast.tier_2_after_minutes', 'type' => 'integer', 'value' => 3],
            ['key' => 'broadcast.tier_3_after_minutes', 'type' => 'integer', 'value' => 6],
            ['key' => 'broadcast.tier_2_surcharge_percent', 'type' => 'integer', 'value' => 20],
            ['key' => 'broadcast.tier_3_surcharge_percent', 'type' => 'integer', 'value' => 50],
            ['key' => 'broadcast.no_driver_after_minutes', 'type' => 'integer', 'value' => 10],

            // Pickup / dropoff geofence
            ['key' => 'pickup.geofence_meters', 'type' => 'integer', 'value' => 500],
            ['key' => 'pickup.dropoff_sanity_meters', 'type' => 'integer', 'value' => 1000],

            // Codes
            ['key' => 'codes.max_attempts', 'type' => 'integer', 'value' => 5],
            ['key' => 'codes.enforce_pickup', 'type' => 'boolean', 'value' => true],
            ['key' => 'codes.enforce_delivery', 'type' => 'boolean', 'value' => true],

            // Driver presence
            ['key' => 'driver.location_stale_after_seconds', 'type' => 'integer', 'value' => 120],
            ['key' => 'driver.idle_offline_after_minutes', 'type' => 'integer', 'value' => 30],
            ['key' => 'driver.gps_lost_offline_after_minutes', 'type' => 'integer', 'value' => 5],

            // Quote
            ['key' => 'quote.ttl_seconds', 'type' => 'integer', 'value' => 300],

            // Cancellation
            ['key' => 'cancellation.user_pre_pickup_fee', 'type' => 'decimal', 'value' => 0.00, 'description' => 'Sender cancellation fee for assigned pre-pickup orders; unresolved business amount defaults to zero'],
            ['key' => 'cancellation.driver_accept_then_cancel_fee', 'type' => 'decimal', 'value' => 0.00, 'description' => 'Driver strike fee for accept-then-cancel support cases; unresolved business amount defaults to zero'],

            // Settlement / payouts
            ['key' => 'payouts.clearance_hours', 'type' => 'integer', 'value' => 48, 'description' => 'Hours between settlement and earning becoming available to seller'],
            ['key' => 'payouts.min_amount', 'type' => 'decimal', 'value' => 20.00, 'description' => 'Minimum amount (LYD) for a single seller payout'],
            ['key' => 'payouts.allow_partial', 'type' => 'boolean', 'value' => true, 'description' => 'Whether sellers may collect a subset of available earnings in one visit'],
            ['key' => 'settlement.reverse_window_hours', 'type' => 'integer', 'value' => '', 'description' => 'Optional hard cap (hours) for admin settlement reversal; empty = no cap, only earnings-state check applies'],

            // Storage / abandonment
            ['key' => 'storage.grace_days', 'type' => 'integer', 'value' => 5, 'description' => 'Free storage days from at_office_at before fees start accruing'],
            ['key' => 'storage.daily_fee', 'type' => 'decimal', 'value' => 1.00, 'description' => 'LYD per day after the grace period, charged to seller at retrieval'],
            ['key' => 'storage.abandonment_days', 'type' => 'integer', 'value' => 30, 'description' => 'Days from at_office_at before the daily cron flips an order to abandoned'],
        ];

        foreach ($defaults as $row) {
            $setting = PlatformSetting::query()->firstOrNew(['key' => $row['key']]);
            // Only set value if creating; preserve any admin tuning that happened post-seed.
            if (! $setting->exists) {
                $setting->type = $row['type'];
                $setting->value = is_scalar($row['value']) ? (string) $row['value'] : (string) json_encode($row['value']);
                $setting->description = $row['description'] ?? null;
                $setting->save();
            }
        }
    }
}
