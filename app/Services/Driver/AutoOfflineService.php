<?php

declare(strict_types=1);

namespace App\Services\Driver;

use App\Enums\DriverActivityStatus;
use App\Models\DriverPresenceLog;
use App\Models\DriverProfile;
use App\Models\Order;
use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class AutoOfflineService
{
    public function runSweep(): int
    {
        return (int) Cache::lock('drivers:auto-offline:sweep', 90)->block(5, function (): int {
            $processed = 0;

            DriverProfile::query()
                ->where('activity_status', DriverActivityStatus::Online->value)
                ->orderBy('id')
                ->chunkById(100, function ($profiles) use (&$processed): void {
                    foreach ($profiles as $profile) {
                        $processed += $this->process($profile);
                    }
                });

            return $processed;
        });
    }

    public function process(DriverProfile $profile): int
    {
        return DB::transaction(function () use ($profile): int {
            $profile = DriverProfile::query()
                ->whereKey($profile->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($profile->activity_status !== DriverActivityStatus::Online) {
                return 0;
            }

            if ($this->hasActiveOrder($profile->user_id)) {
                return 0;
            }

            $reason = $this->offlineReason($profile);
            if ($reason === null) {
                return 0;
            }

            $profile->forceFill([
                'activity_status' => DriverActivityStatus::Offline->value,
                'last_active_at' => now(),
            ])->save();

            DriverPresenceLog::create([
                'driver_id' => $profile->user_id,
                'event' => 'auto_offline',
                'reason' => $reason,
                'location' => $profile->current_location,
            ]);

            return 1;
        });
    }

    private function offlineReason(DriverProfile $profile): ?string
    {
        $lastLocationAt = $profile->last_location_updated_at;
        $lastActiveAt = $profile->last_active_at ?? $lastLocationAt;

        if ($lastLocationAt === null) {
            return 'gps_lost';
        }

        $gpsLostAfter = (int) PlatformSetting::get('driver.gps_lost_offline_after_minutes', 5);
        if ($lastLocationAt->diffInMinutes(now()) >= $gpsLostAfter) {
            return 'gps_lost';
        }

        if ($lastActiveAt === null) {
            return null;
        }

        $idleAfter = (int) PlatformSetting::get('driver.idle_offline_after_minutes', 30);
        if ($lastActiveAt->diffInMinutes(now()) >= $idleAfter) {
            return 'idle';
        }

        return null;
    }

    private function hasActiveOrder(int $driverId): bool
    {
        return Order::query()
            ->forDriver($driverId)
            ->activeForDriver()
            ->exists();
    }
}
