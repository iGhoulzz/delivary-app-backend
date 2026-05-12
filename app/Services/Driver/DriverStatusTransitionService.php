<?php

declare(strict_types=1);

namespace App\Services\Driver;

use App\Enums\DriverErrorCode;
use App\Enums\DriverStatus;
use App\Models\DriverProfile;

final class DriverStatusTransitionService
{
    /** @var array<string, array<int, DriverStatus>> Allowed transitions */
    private const ALLOWED = [
        'pending_approval' => [DriverStatus::Rejected],
        'active' => [DriverStatus::Suspended],
        'suspended' => [DriverStatus::Active],
    ];

    public function reject(DriverProfile $profile): DriverErrorCode|DriverProfile
    {
        return $this->transition($profile, DriverStatus::Rejected, ['rejected_at' => now()]);
    }

    public function suspend(DriverProfile $profile): DriverErrorCode|DriverProfile
    {
        return $this->transition($profile, DriverStatus::Suspended);
    }

    public function reinstate(DriverProfile $profile): DriverErrorCode|DriverProfile
    {
        return $this->transition($profile, DriverStatus::Active);
    }

    /** @param  array<string, mixed>  $extraColumns */
    private function transition(DriverProfile $profile, DriverStatus $to, array $extraColumns = []): DriverErrorCode|DriverProfile
    {
        $allowed = self::ALLOWED[$profile->status->value] ?? [];
        if (! in_array($to, $allowed, true)) {
            return DriverErrorCode::InvalidState;
        }

        $profile->update(array_merge(['status' => $to], $extraColumns));

        return $profile->fresh();
    }
}
