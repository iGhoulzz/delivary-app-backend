<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Enums\DriverActivityStatus;
use App\Enums\DriverStatus;
use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Models\OfficeLocation;
use App\Models\Order;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Http\JsonResponse;

/**
 * Live operations map for the Overview landing screen: active office pins +
 * drivers currently online/on-order with a known position. Reads existing
 * columns (`office_locations.location`, `driver_profiles.current_location`) —
 * no new tables.
 */
final class MapOverviewController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $offices = OfficeLocation::query()
            ->where('is_active', true)
            ->whereNotNull('location')
            ->get(['public_id', 'name', 'location'])
            ->map(fn (OfficeLocation $office): array => [
                'id' => $office->public_id,
                'name' => $office->name,
                'location' => self::point($office->location),
            ])
            ->values();

        $profiles = DriverProfile::query()
            ->where('status', DriverStatus::Active->value)
            ->whereIn('activity_status', [
                DriverActivityStatus::Online->value,
                DriverActivityStatus::OnOrder->value,
            ])
            ->whereNotNull('current_location')
            ->with('user')
            ->get();

        // One grouped query for active loads instead of a count() per driver.
        $loadByDriverId = Order::query()
            ->activeForDriver()
            ->whereIn('driver_id', $profiles->pluck('user_id')->filter()->all())
            ->selectRaw('driver_id, count(*) as aggregate')
            ->groupBy('driver_id')
            ->pluck('aggregate', 'driver_id');

        $drivers = $profiles->map(fn (DriverProfile $profile): array => [
            'id' => $profile->user?->public_id,
            'name' => $profile->user?->fullName(),
            'activity_status' => $profile->activity_status->value,
            'location' => self::point($profile->current_location),
            'active_load' => (int) ($loadByDriverId[$profile->user_id] ?? 0),
        ])->values();

        return response()->json([
            'offices' => $offices,
            'drivers' => $drivers,
        ]);
    }

    /** @return array{lat: float, lng: float}|null */
    private static function point(?Point $point): ?array
    {
        return $point === null
            ? null
            : ['lat' => (float) $point->getLatitude(), 'lng' => (float) $point->getLongitude()];
    }
}
