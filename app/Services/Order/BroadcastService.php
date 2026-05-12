<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Enums\DriverActivityStatus;
use App\Enums\DriverStatus;
use App\Enums\OrderErrorCode;
use App\Enums\OrderStatus;
use App\Enums\VehicleType;
use App\Exceptions\Order\OrderDomainException;
use App\Models\DriverProfile;
use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Support\Collection;

final class BroadcastService
{
    /**
     * @return Collection<int, Order>
     */
    public function candidatesFor(User $driver): Collection
    {
        $profile = $this->freshOnlineProfile($driver);
        $account = $driver->driverAccount;

        if ($account === null || $account->isAtLiabilityCeiling()) {
            return collect();
        }

        return Order::query()
            ->where('status', OrderStatus::AwaitingDriver->value)
            ->whereNull('driver_id')
            ->orderBy('created_at')
            ->limit(100)
            ->get()
            ->filter(function (Order $order) use ($profile, $account): bool {
                if (! in_array($profile->vehicle_type, VehicleType::eligibleFor($order->item_size->value), true)) {
                    return false;
                }

                if (! $account->canHoldAdditionalCash($order->cashCollectedAtDelivery())) {
                    return false;
                }

                $distance = $this->distanceMeters($profile->current_location, $order->pickup_location);
                $order->setAttribute('distance_to_pickup_meters', (int) round($distance));

                return $distance <= $this->radiusMetersForTier($order->search_radius_tier);
            })
            ->sortBy('distance_to_pickup_meters')
            ->values();
    }

    public function freshOnlineProfile(User $driver): DriverProfile
    {
        $profile = DriverProfile::query()->where('user_id', $driver->id)->first();

        if ($profile === null || $profile->status !== DriverStatus::Active) {
            throw new OrderDomainException(
                OrderErrorCode::DriverNotActive,
                trans('order_messages.driver_not_active'),
            );
        }

        if ($profile->activity_status !== DriverActivityStatus::Online) {
            throw new OrderDomainException(
                OrderErrorCode::DriverNotActive,
                trans('order_messages.driver_not_active'),
            );
        }

        if ($profile->current_location === null || $profile->last_location_updated_at === null) {
            throw new OrderDomainException(
                OrderErrorCode::DriverGpsRequired,
                trans('order_messages.driver_gps_required'),
            );
        }

        $staleAfter = (int) PlatformSetting::get('driver.location_stale_after_seconds', 120);
        if ($profile->last_location_updated_at->diffInSeconds(now()) > $staleAfter) {
            throw new OrderDomainException(
                OrderErrorCode::DriverLocationStale,
                trans('order_messages.driver_location_stale'),
            );
        }

        return $profile;
    }

    private function radiusMetersForTier(int $tier): int
    {
        $key = match ($tier) {
            2 => 'broadcast.tier_2_radius_km',
            3 => 'broadcast.tier_3_radius_km',
            default => 'broadcast.tier_1_radius_km',
        };

        return (int) (((float) PlatformSetting::get($key, 3)) * 1000);
    }

    private function distanceMeters(Point $a, Point $b): float
    {
        $earthRadius = 6371000;
        $lat1 = deg2rad($a->getLatitude());
        $lat2 = deg2rad($b->getLatitude());
        $deltaLat = deg2rad($b->getLatitude() - $a->getLatitude());
        $deltaLng = deg2rad($b->getLongitude() - $a->getLongitude());

        $haversine = sin($deltaLat / 2) ** 2
            + cos($lat1) * cos($lat2) * (sin($deltaLng / 2) ** 2);

        return $earthRadius * 2 * atan2(sqrt($haversine), sqrt(1 - $haversine));
    }
}
