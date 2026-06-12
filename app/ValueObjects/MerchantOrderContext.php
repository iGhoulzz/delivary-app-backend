<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Models\MerchantProfile;

/**
 * Carries the merchant dimension through the pricing → quote → creation chain.
 *
 * It holds the negotiated rate overrides AND the business identity, because
 * CreationService writes sender_name/sender_phone from the caller and a merchant
 * order must snapshot the *business* identity, not the owner's personal details.
 *
 * PricingService / QuoteService read only the rate fields; CreationService also
 * reads the identity fields. The customer/P2P path passes null and is unchanged.
 */
final readonly class MerchantOrderContext
{
    public function __construct(
        public int $merchantProfileId,
        public ?string $commissionRateOverride,
        public ?string $driverFeeCutOverride,
        public string $businessName,
        public ?string $contactPhone,
    ) {}

    public static function fromProfile(MerchantProfile $profile): self
    {
        return new self(
            merchantProfileId: $profile->id,
            commissionRateOverride: $profile->commission_rate_override === null
                ? null
                : (string) $profile->commission_rate_override,
            driverFeeCutOverride: $profile->driver_fee_cut_override === null
                ? null
                : (string) $profile->driver_fee_cut_override,
            businessName: (string) $profile->business_name,
            contactPhone: $profile->contactPhone(),
        );
    }
}
