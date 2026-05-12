<?php

namespace Database\Seeders;

use App\Models\PlatformSetting;
use Illuminate\Database\Seeder;

class PlatformSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            // Driver assignment
            ['key' => 'driver_assignment_timeout_minutes', 'value' => '10', 'type' => 'integer',
                'description' => 'Minutes before unfulfilled order is marked no_driver_available'],
            ['key' => 'initial_search_radius_meters', 'value' => '3000', 'type' => 'integer',
                'description' => 'Tier-1 driver search radius (meters)'],
            ['key' => 'tier_2_radius_meters', 'value' => '5000', 'type' => 'integer',
                'description' => 'Tier-2 driver search radius (meters)'],
            ['key' => 'tier_2_minutes', 'value' => '3', 'type' => 'integer',
                'description' => 'Minutes before expanding to tier 2'],
            ['key' => 'tier_2_surcharge_percent', 'value' => '20', 'type' => 'integer',
                'description' => 'Tier-2 delivery surcharge (percent)'],
            ['key' => 'tier_3_radius_meters', 'value' => '10000', 'type' => 'integer',
                'description' => 'Tier-3 driver search radius (meters)'],
            ['key' => 'tier_3_minutes', 'value' => '6', 'type' => 'integer',
                'description' => 'Minutes before expanding to tier 3'],
            ['key' => 'tier_3_surcharge_percent', 'value' => '50', 'type' => 'integer',
                'description' => 'Tier-3 delivery surcharge (percent)'],

            // Commissions / fees
            ['key' => 'commission_rate_default', 'value' => '0.00', 'type' => 'decimal',
                'description' => 'Default item commission rate (0% at MVP launch)'],
            ['key' => 'driver_fee_cut_rate', 'value' => '0.02', 'type' => 'decimal',
                'description' => 'Platform cut on every delivery fee (2%)'],

            // Storage / abandonment
            ['key' => 'storage_fee_grace_days', 'value' => '5', 'type' => 'integer',
                'description' => 'Free storage period at office (days)'],
            ['key' => 'abandonment_threshold_days', 'value' => '30', 'type' => 'integer',
                'description' => 'Days after which an unretrieved item is abandoned'],

            // Payouts
            ['key' => 'pending_clearance_hours', 'value' => '48', 'type' => 'integer',
                'description' => 'Hours after settlement before seller funds clear'],
            ['key' => 'min_payout_amount', 'value' => '20.00', 'type' => 'decimal',
                'description' => 'Minimum seller payout amount (LYD)'],

            // Driver liability
            ['key' => 'new_driver_max_liability', 'value' => '100.00', 'type' => 'decimal',
                'description' => 'Initial max cash liability for new drivers (LYD)'],
            ['key' => 'veteran_driver_max_liability', 'value' => '500.00', 'type' => 'decimal',
                'description' => 'Max cash liability after 50+ deliveries (LYD)'],

            // Geofence + driver runtime
            ['key' => 'pickup_geofence_meters', 'value' => '500', 'type' => 'integer',
                'description' => 'Tolerance for pickup geofence fallback (meters)'],
            ['key' => 'gps_lost_minutes_before_offline', 'value' => '5', 'type' => 'integer',
                'description' => 'Minutes without GPS before driver auto-offline'],
            ['key' => 'inactivity_minutes_before_offline', 'value' => '30', 'type' => 'integer',
                'description' => 'Minutes inactive before driver auto-offline'],

            // Strikes
            ['key' => 'strike_review_threshold', 'value' => '3', 'type' => 'integer',
                'description' => 'Strikes within 30 days that trigger admin review'],
            ['key' => 'strike_suspension_threshold', 'value' => '5', 'type' => 'integer',
                'description' => 'Strikes that trigger suspension'],
        ];

        foreach ($defaults as $row) {
            PlatformSetting::updateOrCreate(['key' => $row['key']], $row);
        }
    }
}
