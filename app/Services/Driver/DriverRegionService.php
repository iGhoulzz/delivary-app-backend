<?php

declare(strict_types=1);

namespace App\Services\Driver;

use App\Enums\DriverErrorCode;
use App\Models\DriverProfile;
use App\Models\Region;
use Illuminate\Database\Eloquent\Collection;

final class DriverRegionService
{
    /** Returns active regions in the driver's office's service area. */
    public function availableForDriver(DriverProfile $profile): Collection
    {
        $office = $profile->office;
        if ($office === null || $office->region_id === null) {
            return new Collection;
        }
        $office->loadMissing('region');
        $serviceAreaId = $office->region?->service_area_id;
        if ($serviceAreaId === null) {
            return new Collection;
        }

        return Region::query()
            ->where('service_area_id', $serviceAreaId)
            ->where('is_active', true)
            ->get();
    }

    /**
     * Sync the driver's region selection. Empty array = "all available".
     * Validates every region_id is within the driver's office service area.
     *
     * @param  array<int, int>  $regionIds
     * @return DriverErrorCode|Collection<int, Region>
     */
    public function update(DriverProfile $profile, array $regionIds): DriverErrorCode|Collection
    {
        $available = $this->availableForDriver($profile);
        $availableIds = $available->pluck('id')->all();

        foreach ($regionIds as $id) {
            if (! in_array((int) $id, $availableIds, true)) {
                return DriverErrorCode::OutsideServiceArea;
            }
        }

        $profile->user->driverRegions()->sync($regionIds);

        return Region::whereIn('id', $regionIds)->get();
    }
}
