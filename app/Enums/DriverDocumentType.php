<?php

declare(strict_types=1);

namespace App\Enums;

enum DriverDocumentType: string
{
    case NationalIdFront = 'national_id_front';
    case NationalIdBack = 'national_id_back';
    case DriversLicense = 'drivers_license';
    case VehicleRegistration = 'vehicle_registration';
    case Selfie = 'selfie';
    case VehiclePhotoFront = 'vehicle_photo_front';
    case VehiclePhotoBack = 'vehicle_photo_back';
    case Insurance = 'insurance';

    public function label(): string
    {
        return match ($this) {
            self::NationalIdFront => 'National ID (Front)',
            self::NationalIdBack => 'National ID (Back)',
            self::DriversLicense => "Driver's License",
            self::VehicleRegistration => 'Vehicle Registration',
            self::Selfie => 'Selfie',
            self::VehiclePhotoFront => 'Vehicle Photo (Front)',
            self::VehiclePhotoBack => 'Vehicle Photo (Back)',
            self::Insurance => 'Insurance',
        };
    }

    /**
     * The minimum set of documents required before a driver can be approved.
     * Insurance is currently optional.
     *
     * @return array<int, self>
     */
    public static function requiredForApproval(): array
    {
        return [
            self::NationalIdFront,
            self::NationalIdBack,
            self::DriversLicense,
            self::VehicleRegistration,
            self::Selfie,
            self::VehiclePhotoFront,
            self::VehiclePhotoBack,
        ];
    }
}
