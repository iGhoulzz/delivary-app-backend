<?php

declare(strict_types=1);

namespace App\Services\Driver;

use App\Enums\DriverErrorCode;
use App\Enums\DriverStatus;
use App\Models\DriverAccount;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DriverApprovalService
{
    /**
     * Atomic approval: state flip + driver_account creation + role assignment +
     * docs verified. Either everything happens or nothing does.
     */
    public function approve(DriverProfile $profile, User $admin): DriverErrorCode|DriverProfile
    {
        if ($profile->status !== DriverStatus::PendingApproval) {
            return DriverErrorCode::InvalidState;
        }

        return DB::transaction(function () use ($profile, $admin): DriverProfile {
            $profile->update([
                'status' => DriverStatus::Active,
                'approved_at' => now(),
                'approved_by_admin_id' => $admin->id,
            ]);

            DriverAccount::firstOrCreate(
                ['driver_id' => $profile->user_id],
                [
                    'cash_to_deposit' => '0.00',
                    'earnings_balance' => '0.00',
                    'debt_balance' => '0.00',
                    'max_cash_liability' => PlatformSetting::get('new_driver_max_liability', '100.00'),
                ],
            );

            $profile->user->assignRole('driver');

            DriverDocument::where('driver_id', $profile->user_id)
                ->update([
                    'verified' => true,
                    'verified_by_admin_id' => $admin->id,
                    'verified_at' => now(),
                ]);

            return $profile->fresh(['user', 'office']);
        });
    }
}
