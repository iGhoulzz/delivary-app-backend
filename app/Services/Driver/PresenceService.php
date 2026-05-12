<?php

declare(strict_types=1);

namespace App\Services\Driver;

use App\Enums\DriverActivityStatus;
use App\Enums\DriverStatus;
use App\Enums\OrderErrorCode;
use App\Exceptions\Order\OrderDomainException;
use App\Models\DriverLocation;
use App\Models\DriverPresenceLog;
use App\Models\DriverProfile;
use App\Models\Order;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Support\Facades\DB;

final class PresenceService
{
    /**
     * @param  array<string, mixed>  $location
     */
    public function goOnline(User $driver, array $location): DriverProfile
    {
        $point = $this->pointFromPayload($location);

        return DB::transaction(function () use ($driver, $location, $point): DriverProfile {
            $profile = $this->profileFor($driver, lock: true);
            $this->assertCanGoOnline($driver, $profile, $point);

            $profile->forceFill([
                'activity_status' => DriverActivityStatus::Online->value,
                'current_location' => $point,
                'last_location_updated_at' => now(),
                'last_active_at' => now(),
            ])->save();

            $this->recordLocationIfNeeded($driver, $point, $location);
            $this->logPresence($driver, 'went_online', 'manual', $point);

            return $profile->refresh();
        });
    }

    public function goOffline(User $driver, ?string $reason = null): DriverProfile
    {
        return DB::transaction(function () use ($driver, $reason): DriverProfile {
            $profile = $this->profileFor($driver, lock: true);

            if ($this->hasActiveOrder($driver)) {
                throw new OrderDomainException(
                    OrderErrorCode::DriverHasActiveOrder,
                    trans('order_messages.driver_has_active_order'),
                );
            }

            $profile->forceFill([
                'activity_status' => DriverActivityStatus::Offline->value,
                'last_active_at' => now(),
            ])->save();

            $this->logPresence($driver, 'went_offline', $reason ?? 'manual', $profile->current_location);

            return $profile->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $location
     */
    public function updateLocation(User $driver, array $location): DriverProfile
    {
        $point = $this->pointFromPayload($location);

        return DB::transaction(function () use ($driver, $location, $point): DriverProfile {
            $profile = $this->profileFor($driver, lock: true);

            if ($profile->status !== DriverStatus::Active) {
                throw new OrderDomainException(
                    OrderErrorCode::DriverNotActive,
                    trans('order_messages.driver_not_active'),
                );
            }

            $profile->forceFill([
                'current_location' => $point,
                'last_location_updated_at' => now(),
                'last_active_at' => now(),
            ])->save();

            $this->recordLocationIfNeeded($driver, $point, $location);

            return $profile->refresh();
        });
    }

    private function profileFor(User $driver, bool $lock = false): DriverProfile
    {
        $query = DriverProfile::query()->where('user_id', $driver->id);

        if ($lock) {
            $query->lockForUpdate();
        }

        $profile = $query->first();

        if ($profile === null || $profile->status !== DriverStatus::Active) {
            throw new OrderDomainException(
                OrderErrorCode::DriverNotActive,
                trans('order_messages.driver_not_active'),
            );
        }

        return $profile;
    }

    private function assertCanGoOnline(User $driver, DriverProfile $profile, Point $point): void
    {
        if (! $profile->status->canGoOnline()) {
            throw new OrderDomainException(
                OrderErrorCode::DriverNotActive,
                trans('order_messages.driver_not_active'),
            );
        }

        if (! $this->pointInsideActiveServiceArea($point)) {
            throw new OrderDomainException(
                OrderErrorCode::DriverOutOfServiceArea,
                trans('order_messages.driver_out_of_service_area'),
            );
        }

        if ($this->hasActiveOrder($driver)) {
            throw new OrderDomainException(
                OrderErrorCode::DriverHasActiveOrder,
                trans('order_messages.driver_has_active_order'),
            );
        }

        $account = $driver->driverAccount()->lockForUpdate()->first();

        if ($account === null || $account->isAtLiabilityCeiling()) {
            throw new OrderDomainException(
                OrderErrorCode::DriverLiabilityMax,
                trans('order_messages.driver_liability_max'),
            );
        }

        if (bccomp((string) $account->debt_balance, '0.00', 2) === 1) {
            throw new OrderDomainException(
                OrderErrorCode::DriverBlockedByDebt,
                trans('order_messages.driver_blocked_by_debt'),
            );
        }
    }

    private function hasActiveOrder(User $driver): bool
    {
        return Order::query()
            ->forDriver($driver->id)
            ->active()
            ->exists();
    }

    private function pointInsideActiveServiceArea(Point $point): bool
    {
        $row = DB::selectOne(
            'SELECT EXISTS (
                SELECT 1
                  FROM service_areas
                 WHERE is_active = true
                   AND ST_Contains(boundary::geometry, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geometry)
            ) AS inside',
            [$point->getLongitude(), $point->getLatitude()],
        );

        return (bool) ($row?->inside ?? false);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function pointFromPayload(array $payload): Point
    {
        if (! isset($payload['lat'], $payload['lng'])) {
            throw new OrderDomainException(
                OrderErrorCode::DriverGpsRequired,
                trans('order_messages.driver_gps_required'),
            );
        }

        return Point::makeGeodetic((float) $payload['lat'], (float) $payload['lng']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordLocationIfNeeded(User $driver, Point $point, array $payload): void
    {
        $latest = DriverLocation::query()
            ->where('driver_id', $driver->id)
            ->orderByDesc('recorded_at')
            ->first();

        $shouldRecord = $latest === null
            || $latest->recorded_at?->diffInSeconds(now()) >= 60
            || ($latest->location !== null && $this->distanceMeters($latest->location, $point) >= 50);

        if (! $shouldRecord) {
            return;
        }

        DriverLocation::create([
            'driver_id' => $driver->id,
            'location' => $point,
            'heading' => $payload['heading'] ?? null,
            'speed_mps' => $payload['speed_mps'] ?? null,
            'accuracy_meters' => $payload['accuracy_meters'] ?? null,
            'battery_percentage' => $payload['battery_percentage'] ?? null,
            'recorded_at' => now(),
        ]);
    }

    private function logPresence(User $driver, string $event, string $reason, ?Point $point): void
    {
        DriverPresenceLog::create([
            'driver_id' => $driver->id,
            'event' => $event,
            'reason' => $reason,
            'location' => $point,
        ]);
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
