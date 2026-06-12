<?php

declare(strict_types=1);

namespace App\Services\Merchant;

use App\Enums\AccountStatus;
use App\Enums\MerchantErrorCode;
use App\Enums\MerchantStatus;
use App\Exceptions\Merchant\MerchantException;
use App\Models\MerchantProfile;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Support\Facades\DB;

final class MerchantOnboardingService
{
    /** @param array<string, mixed> $data */
    public function create(User $admin, array $data): MerchantProfile
    {
        return DB::transaction(function () use ($admin, $data): MerchantProfile {
            $user = User::query()
                ->where('public_id', (string) $data['user_public_id'])
                ->lockForUpdate()
                ->first();

            if ($user === null) {
                throw new MerchantException(
                    MerchantErrorCode::UserNotFound,
                    trans('merchant_messages.user_not_found'),
                );
            }

            if ($user->account_status === AccountStatus::Banned) {
                throw new MerchantException(
                    MerchantErrorCode::AccountNotEligible,
                    trans('merchant_messages.account_not_eligible'),
                );
            }

            $existing = MerchantProfile::withTrashed()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (! $existing->trashed() || $existing->status === MerchantStatus::Banned) {
                    throw new MerchantException(
                        MerchantErrorCode::AlreadyMerchant,
                        trans('merchant_messages.already_merchant'),
                    );
                }

                $existing->restore();
                $profile = $existing;
            } else {
                $profile = new MerchantProfile(['user_id' => $user->id]);
            }

            $profile->fill($this->profileFields($data));
            $profile->status = MerchantStatus::Active->value;
            $profile->approved_at = now();
            $profile->approved_by_admin_id = $admin->id;
            $profile->created_by_admin_id ??= $admin->id;
            $profile->save();

            $user->assignRole('merchant');

            return $profile->fresh(['user']);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $admin, MerchantProfile $merchant, array $data): MerchantProfile
    {
        return DB::transaction(function () use ($merchant, $data): MerchantProfile {
            $locked = MerchantProfile::query()
                ->whereKey($merchant->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $locked->fill($this->profileFields($data, partial: true));
            $locked->save();

            return $locked->fresh(['user']);
        });
    }

    public function suspend(User $admin, MerchantProfile $merchant): MerchantProfile
    {
        return $this->transition($merchant, [MerchantStatus::Active], MerchantStatus::Suspended);
    }

    public function reactivate(User $admin, MerchantProfile $merchant): MerchantProfile
    {
        return $this->transition(
            merchant: $merchant,
            allowedFrom: [MerchantStatus::Suspended],
            to: MerchantStatus::Active,
            afterSave: fn (MerchantProfile $profile) => $profile->user->assignRole('merchant'),
        );
    }

    public function ban(User $admin, MerchantProfile $merchant): MerchantProfile
    {
        return $this->transition(
            merchant: $merchant,
            allowedFrom: [MerchantStatus::Active, MerchantStatus::Suspended],
            to: MerchantStatus::Banned,
            afterSave: fn (MerchantProfile $profile) => $profile->user->removeRole('merchant'),
        );
    }

    /**
     * @param  list<MerchantStatus>  $allowedFrom
     * @param  callable(MerchantProfile): mixed|null  $afterSave
     */
    private function transition(
        MerchantProfile $merchant,
        array $allowedFrom,
        MerchantStatus $to,
        ?callable $afterSave = null,
    ): MerchantProfile {
        return DB::transaction(function () use ($merchant, $allowedFrom, $to, $afterSave): MerchantProfile {
            $locked = MerchantProfile::query()
                ->with('user')
                ->whereKey($merchant->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($locked->status, $allowedFrom, true)) {
                throw new MerchantException(
                    MerchantErrorCode::InvalidStatusTransition,
                    trans('merchant_messages.invalid_status_transition'),
                );
            }

            $locked->status = $to->value;
            $locked->save();

            $afterSave?->call($this, $locked);

            return $locked->fresh(['user']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function profileFields(array $data, bool $partial = false): array
    {
        $fields = [];

        foreach ([
            'business_name',
            'business_phone',
            'commission_rate_override',
            'driver_fee_cut_override',
            'default_pickup_address',
            'notes',
        ] as $key) {
            if (! $partial || array_key_exists($key, $data)) {
                $fields[$key] = $data[$key] ?? null;
            }
        }

        if (! $partial || array_key_exists('default_pickup_location', $data)) {
            $fields['default_pickup_location'] = $this->pointFromInput($data['default_pickup_location'] ?? null);
        }

        return $fields;
    }

    private function pointFromInput(mixed $location): ?Point
    {
        if (! is_array($location)) {
            return null;
        }

        return Point::makeGeodetic(
            (float) $location['lat'],
            (float) $location['lng'],
        );
    }
}
