<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Enums\OrderErrorCode;
use App\Exceptions\Order\OrderDomainException;
use App\Models\OfficeLocation;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Support\Facades\DB;

final class ReturnOfficeResolver
{
    public function resolveForPickup(Point $pickup): OfficeLocation
    {
        $longitude = $pickup->getLongitude();
        $latitude = $pickup->getLatitude();

        $regionRow = DB::selectOne(
            'SELECT r.office_id
               FROM regions r
               JOIN office_locations o ON o.id = r.office_id
              WHERE r.office_id IS NOT NULL
                AND o.is_active = true
                AND ST_Contains(r.boundary::geometry, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geometry)
              LIMIT 1',
            [$longitude, $latitude],
        );

        if ($regionRow !== null) {
            return OfficeLocation::query()->findOrFail((int) $regionRow->office_id);
        }

        $nearestRow = DB::selectOne(
            'SELECT id
               FROM office_locations
              WHERE is_active = true
                AND location IS NOT NULL
              ORDER BY ST_Distance(location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) ASC
              LIMIT 1',
            [$longitude, $latitude],
        );

        if ($nearestRow !== null) {
            return OfficeLocation::query()->findOrFail((int) $nearestRow->id);
        }

        throw new OrderDomainException(
            OrderErrorCode::NoReturnOfficeAvailable,
            trans('order_messages.no_return_office_available'),
        );
    }
}
