<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MapZonesController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $serviceAreas = DB::select(
            'SELECT id, name, is_active, ST_AsGeoJSON(boundary, 6) AS geometry FROM service_areas'
        );
        $regions = DB::select(
            'SELECT r.id, r.name, r.is_active, r.service_area_id, r.base_fee,
                    o.public_id AS office_public_id, o.name AS office_name,
                    ST_AsGeoJSON(r.boundary, 6) AS geometry
               FROM regions r
               LEFT JOIN office_locations o ON o.id = r.office_id AND o.deleted_at IS NULL'
        );

        $features = [];
        foreach ($serviceAreas as $serviceArea) {
            $features[] = [
                'type' => 'Feature',
                'geometry' => json_decode((string) $serviceArea->geometry, true, flags: JSON_THROW_ON_ERROR),
                'properties' => [
                    'kind' => 'service_area',
                    'id' => (int) $serviceArea->id,
                    'name' => $serviceArea->name,
                    'is_active' => (bool) $serviceArea->is_active,
                ],
            ];
        }

        foreach ($regions as $region) {
            $features[] = [
                'type' => 'Feature',
                'geometry' => json_decode((string) $region->geometry, true, flags: JSON_THROW_ON_ERROR),
                'properties' => [
                    'kind' => 'region',
                    'id' => (int) $region->id,
                    'name' => $region->name,
                    'is_active' => (bool) $region->is_active,
                    'service_area_id' => (int) $region->service_area_id,
                    'base_fee' => $region->base_fee !== null ? (string) $region->base_fee : null,
                    'office' => $region->office_public_id !== null
                        ? [
                            'id' => $region->office_public_id,
                            'name' => $region->office_name,
                        ]
                        : null,
                ],
            ];
        }

        return response()->json([
            'type' => 'FeatureCollection',
            'features' => $features,
        ]);
    }
}
