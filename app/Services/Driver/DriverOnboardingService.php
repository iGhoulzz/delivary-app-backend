<?php

declare(strict_types=1);

namespace App\Services\Driver;

use App\Enums\AccountStatus;
use App\Enums\DriverActivityStatus;
use App\Enums\DriverDocumentType;
use App\Enums\DriverErrorCode;
use App\Enums\DriverStatus;
use App\Enums\OtpPurpose;
use App\Enums\VehicleType;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Auth\OtpService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DriverOnboardingService
{
    public function __construct(private readonly OtpService $otp) {}

    /**
     * @return array{
     *     user_exists: bool,
     *     user_phone_verified: bool,
     *     driver_profile: ?DriverProfile,
     *     can_onboard: bool,
     *     reason_if_not: ?string,
     * }
     */
    public function findByPhoneForOffice(string $phone, int $staffOfficeId): array
    {
        $user = User::where('phone_number', $phone)->first();

        if ($user === null) {
            return [
                'user_exists' => false,
                'user_phone_verified' => false,
                'driver_profile' => null,
                'can_onboard' => true,
                'reason_if_not' => null,
            ];
        }

        $profile = $user->driverProfile;

        if ($profile === null) {
            return [
                'user_exists' => true,
                'user_phone_verified' => $user->phone_verified_at !== null,
                'driver_profile' => null,
                'can_onboard' => true,
                'reason_if_not' => null,
            ];
        }

        if ($profile->office_id !== $staffOfficeId) {
            return [
                'user_exists' => true,
                'user_phone_verified' => $user->phone_verified_at !== null,
                'driver_profile' => $profile,
                'can_onboard' => false,
                'reason_if_not' => 'belongs_to_other_office',
            ];
        }

        $canContinue = in_array(
            $profile->status,
            [DriverStatus::PreRegistered, DriverStatus::PendingApproval],
            true,
        );

        return [
            'user_exists' => true,
            'user_phone_verified' => $user->phone_verified_at !== null,
            'driver_profile' => $profile,
            'can_onboard' => $canContinue,
            'reason_if_not' => $canContinue ? null : 'profile_state_'.$profile->status->value,
        ];
    }

    /**
     * Single-entry onboarding for office staff. See spec §5.2.
     *
     * @param  array{
     *     phone_number: string,
     *     first_name?: ?string,
     *     last_name?: ?string,
     *     vehicle_type: string,
     *     vehicle_plate: string,
     *     vehicle_color?: ?string,
     *     vehicle_model?: ?string,
     * }  $data
     * @return array{
     *     driver_profile: DriverProfile,
     *     otp_required: bool,
     *     otp_expires_at: ?CarbonInterface,
     * } | DriverErrorCode
     */
    public function onboard(int $staffOfficeId, array $data): array|DriverErrorCode
    {
        $result = DB::transaction(function () use ($staffOfficeId, $data): array|DriverErrorCode {
            $phone = $data['phone_number'];
            $user = User::where('phone_number', $phone)->first();
            $otpRequired = false;

            if ($user === null) {
                // Path C — cold walk-in. Random throwaway password; the
                // driver will reset via the standard password-reset flow once
                // they need API access of their own.
                $user = User::create([
                    'phone_number' => $phone,
                    'first_name' => $data['first_name'] ?? '',
                    'last_name' => $data['last_name'] ?? null,
                    'password' => Str::random(32),
                    'locale' => 'ar',
                    'account_status' => AccountStatus::Active,
                    'phone_verified_at' => null,
                ]);
                $user->assignRole('user');
                $otpRequired = true;
            }

            $profile = $user->driverProfile;

            if ($profile !== null) {
                if ($profile->office_id !== $staffOfficeId) {
                    return DriverErrorCode::WrongOffice;
                }
                $profile->update([
                    'vehicle_type' => VehicleType::from($data['vehicle_type']),
                    'vehicle_plate' => $data['vehicle_plate'],
                    'vehicle_color' => $data['vehicle_color'] ?? $profile->vehicle_color,
                    'vehicle_model' => $data['vehicle_model'] ?? $profile->vehicle_model,
                ]);
            } else {
                $profile = DriverProfile::create([
                    'user_id' => $user->id,
                    'office_id' => $staffOfficeId,
                    'status' => DriverStatus::PreRegistered,
                    'activity_status' => DriverActivityStatus::Offline,
                    'vehicle_type' => VehicleType::from($data['vehicle_type']),
                    'vehicle_plate' => $data['vehicle_plate'],
                    'vehicle_color' => $data['vehicle_color'] ?? null,
                    'vehicle_model' => $data['vehicle_model'] ?? null,
                ]);
            }

            return [
                'driver_profile' => $profile,
                'otp_required' => $otpRequired,
                'phone' => $phone,
            ];
        });

        if ($result instanceof DriverErrorCode) {
            return $result;
        }

        // OTP issuance happens AFTER the transaction commits — SMS failure
        // shouldn't roll back the user/profile creation.
        $otpExpiresAt = null;
        if ($result['otp_required']) {
            $this->otp->issue($result['phone'], OtpPurpose::Registration);
            $otpExpiresAt = now()->addSeconds((int) PlatformSetting::get('otp_ttl_seconds', 300));
        }

        return [
            'driver_profile' => $result['driver_profile'],
            'otp_required' => $result['otp_required'],
            'otp_expires_at' => $otpExpiresAt,
        ];
    }

    /**
     * Validates required documents are uploaded + phone is verified, then
     * transitions pre_registered → pending_approval.
     *
     * @return DriverErrorCode | array{driver_profile: DriverProfile}
     */
    public function submitForApproval(DriverProfile $profile): DriverErrorCode|array
    {
        if ($profile->status !== DriverStatus::PreRegistered) {
            return DriverErrorCode::InvalidState;
        }
        if ($profile->user->phone_verified_at === null) {
            return DriverErrorCode::PhoneNotVerified;
        }

        $missing = $this->missingDocumentsFor($profile);
        if ($missing !== []) {
            return DriverErrorCode::MissingDocuments;
        }

        $profile->update(['status' => DriverStatus::PendingApproval]);

        return ['driver_profile' => $profile->fresh()];
    }

    /** @return array<int, string> */
    public function missingDocumentsFor(DriverProfile $profile): array
    {
        $required = [
            'national_id_front', 'national_id_back', 'drivers_license', 'selfie',
            'vehicle_registration', 'vehicle_photo_front', 'vehicle_photo_back',
        ];

        $present = DriverDocument::where('driver_id', $profile->user_id)
            ->pluck('document_type')
            ->map(fn ($t) => $t instanceof DriverDocumentType ? $t->value : (string) $t)
            ->all();

        return array_values(array_diff($required, $present));
    }
}
