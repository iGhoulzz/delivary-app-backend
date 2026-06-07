<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\OfficeLocation;
use App\Models\Region;
use App\Models\ServiceArea;
use Clickbar\Magellan\Data\Geometries\LineString;
use Clickbar\Magellan\Data\Geometries\Point;
use Clickbar\Magellan\Data\Geometries\Polygon;
use Database\Seeders\OrderLifecyclePlatformSettingsSeeder;
use Database\Seeders\PlatformSettingsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Builds the geographic + platform baseline that the e2e/smoke tests need.
 *
 * A fresh test DB (RefreshDatabase) is empty — unlike the seeded dev DB the
 * Tinker smoke scripts assumed. There is no Region/ServiceArea factory, so this
 * helper seeds roles + platform settings and constructs one active
 * service-area → region → office around Tripoli, returning an in-area
 * pickup/dropoff pair for QuoteService/CreationService.
 */
final class TestWorld
{
    /**
     * @return array{
     *     region: Region,
     *     office: OfficeLocation,
     *     pickup: array{lat: float, lng: float},
     *     dropoff: array{lat: float, lng: float}
     * }
     */
    public static function create(): array
    {
        Artisan::call('db:seed', ['--class' => RolesSeeder::class, '--no-interaction' => true]);
        Artisan::call('db:seed', ['--class' => PlatformSettingsSeeder::class, '--no-interaction' => true]);
        Artisan::call('db:seed', ['--class' => OrderLifecyclePlatformSettingsSeeder::class, '--no-interaction' => true]);

        // Closed square ring around Tripoli centre (lat 32.80–32.95, lng 13.10–13.30).
        // Point::makeGeodetic(lat, lng) carries SRID 4326; pass 4326 to the wrappers too.
        $ring = LineString::make([
            Point::makeGeodetic(32.80, 13.10),
            Point::makeGeodetic(32.80, 13.30),
            Point::makeGeodetic(32.95, 13.30),
            Point::makeGeodetic(32.95, 13.10),
            Point::makeGeodetic(32.80, 13.10), // close the ring
        ], 4326);
        $boundary = Polygon::make([$ring], 4326);

        $serviceArea = ServiceArea::create([
            'name' => 'Test Service Area',
            'boundary' => $boundary,
            'is_active' => true,
        ]);

        $region = Region::create([
            'service_area_id' => $serviceArea->id,
            'name' => 'Test Region',
            'boundary' => $boundary,
            'is_active' => true,
            'base_fee' => '10.00',
        ]);

        $office = OfficeLocation::create([
            'region_id' => $region->id,
            'name' => 'Test Office',
            'address' => 'Test office address',
            'location' => Point::makeGeodetic(32.8872, 13.1913),
            'is_active' => true,
        ]);

        $region->forceFill(['office_id' => $office->id])->save();

        return [
            'region' => $region->fresh(),
            'office' => $office,
            'pickup' => ['lat' => 32.8872, 'lng' => 13.1913],
            'dropoff' => ['lat' => 32.8882, 'lng' => 13.1923],
        ];
    }
}
