<?php

declare(strict_types=1);

namespace App\Services\Merchant;

use App\Enums\MerchantErrorCode;
use App\Enums\MerchantStatus;
use App\Enums\OrderType;
use App\Exceptions\Merchant\MerchantException;
use App\Models\MerchantProfile;
use App\Models\Order;
use App\Models\User;
use App\Services\Order\CreationService;
use App\ValueObjects\MerchantOrderContext;

/**
 * Merchant-facing order entry point. Resolves pickup (all-or-nothing, with a
 * fallback to the profile default), then delegates the transactional write to
 * the shared CreationService with a MerchantOrderContext.
 */
final class MerchantOrderCreationService
{
    public function __construct(private readonly CreationService $creation) {}

    public function create(User $merchantUser, array $input, ?string $idempotencyKey = null): Order
    {
        $profile = $this->requireActiveProfile($merchantUser);
        $input = $this->resolvePickup($input, $profile);
        $input['order_type'] = OrderType::MerchantDelivery->value;

        return $this->creation->create(
            $merchantUser,
            $input,
            $idempotencyKey,
            MerchantOrderContext::fromProfile($profile),
        );
    }

    public function requireActiveProfile(User $merchantUser): MerchantProfile
    {
        $profile = $merchantUser->merchantProfile;

        if ($profile === null || $profile->status !== MerchantStatus::Active) {
            throw new MerchantException(
                MerchantErrorCode::MerchantNotActive,
                trans('merchant_messages.merchant_not_active'),
            );
        }

        return $profile;
    }

    /**
     * All-or-nothing pickup: a per-order pickup must supply BOTH address and
     * location; a partial one is rejected (never silently defaulted). When no
     * per-order pickup is present, fall back to the profile default (both
     * fields together) or throw MissingPickup.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function resolvePickup(array $input, MerchantProfile $profile): array
    {
        $address = $input['pickup_address'] ?? null;
        $lat = $input['pickup_location']['lat'] ?? null;
        $lng = $input['pickup_location']['lng'] ?? null;

        $anyPresent = $address !== null || $lat !== null || $lng !== null;
        $allPresent = $address !== null && $lat !== null && $lng !== null;

        if ($anyPresent && ! $allPresent) {
            throw new MerchantException(
                MerchantErrorCode::MissingPickup,
                trans('merchant_messages.missing_pickup'),
            );
        }

        if ($allPresent) {
            return $input;
        }

        if ($profile->default_pickup_address !== null && $profile->default_pickup_location !== null) {
            $input['pickup_address'] = $profile->default_pickup_address;
            $input['pickup_location'] = [
                'lat' => $profile->default_pickup_location->getLatitude(),
                'lng' => $profile->default_pickup_location->getLongitude(),
            ];

            return $input;
        }

        throw new MerchantException(
            MerchantErrorCode::MissingPickup,
            trans('merchant_messages.missing_pickup'),
        );
    }
}
