<?php

declare(strict_types=1);

use App\Models\MerchantProfile;
use App\Models\OfficeLocation;
use App\Models\Region;
use App\Models\ServiceArea;
use App\Support\Resolvers\PublicIdResolver;
use Clickbar\Magellan\Data\Geometries\LineString;
use Clickbar\Magellan\Data\Geometries\Point;
use Clickbar\Magellan\Data\Geometries\Polygon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function publicIdResolverBoundary(): Polygon
{
    return Polygon::make([
        LineString::make([
            Point::makeGeodetic(13.0, 32.0),
            Point::makeGeodetic(13.0, 32.1),
            Point::makeGeodetic(13.1, 32.1),
            Point::makeGeodetic(13.1, 32.0),
            Point::makeGeodetic(13.0, 32.0),
        ]),
    ], 4326);
}

it('resolves an office public_id to its internal id', function (): void {
    $serviceArea = ServiceArea::create([
        'name' => 'SA1',
        'boundary' => publicIdResolverBoundary(),
        'is_active' => true,
    ]);
    $region = Region::create([
        'service_area_id' => $serviceArea->id,
        'name' => 'R1',
        'boundary' => publicIdResolverBoundary(),
        'is_active' => true,
    ]);
    $office = OfficeLocation::create([
        'region_id' => $region->id, 'name' => 'O1', 'address' => 'a',
        'location' => Point::makeGeodetic(32.0, 13.0), 'is_active' => true,
    ]);

    expect(PublicIdResolver::officeId($office->public_id))->toBe($office->id);
    expect(PublicIdResolver::officeId(null))->toBeNull();
});

it('resolves a merchant profile public_id to its internal id', function (): void {
    $merchant = MerchantProfile::factory()->create();

    expect(PublicIdResolver::merchantProfileId($merchant->public_id))->toBe($merchant->id);
    expect(PublicIdResolver::merchantProfileId(null))->toBeNull();
});
