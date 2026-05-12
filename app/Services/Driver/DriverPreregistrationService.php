<?php

declare(strict_types=1);

namespace App\Services\Driver;

use App\Enums\DriverActivityStatus;
use App\Enums\DriverErrorCode;
use App\Enums\DriverStatus;
use App\Enums\VehicleType;
use App\Models\DriverProfile;
use App\Models\User;

final class DriverPreregistrationService
{
    /**
     * @param  array{
     *     office_id: int,
     *     vehicle_type: string,
     *     vehicle_plate: string,
     *     vehicle_color?: ?string,
     *     vehicle_model?: ?string,
     * }  $data
     */
    public function preregister(User $user, array $data): DriverProfile|DriverErrorCode
    {
        if ($user->driverProfile()->exists()) {
            return DriverErrorCode::DriverProfileExists;
        }

        return DriverProfile::create([
            'user_id' => $user->id,
            'office_id' => $data['office_id'],
            'status' => DriverStatus::PreRegistered,
            'activity_status' => DriverActivityStatus::Offline,
            'vehicle_type' => VehicleType::from($data['vehicle_type']),
            'vehicle_plate' => $data['vehicle_plate'],
            'vehicle_color' => $data['vehicle_color'] ?? null,
            'vehicle_model' => $data['vehicle_model'] ?? null,
        ]);
    }
}
