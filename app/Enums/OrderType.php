<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderType: string
{
    case StandardDelivery = 'standard_delivery';
    case P2pSale = 'p2p_sale';
    case MerchantDelivery = 'merchant_delivery';

    public function label(): string
    {
        return match ($this) {
            self::StandardDelivery => 'Standard Delivery',
            self::P2pSale => 'P2P Sale',
            self::MerchantDelivery => 'Merchant Delivery',
        };
    }

    /**
     * Whether this order type involves an item price collected at delivery
     * (always cash per spec section 4.6).
     */
    public function hasItemPrice(): bool
    {
        return $this === self::P2pSale || $this === self::MerchantDelivery;
    }

    public function isMerchant(): bool
    {
        return $this === self::MerchantDelivery;
    }
}
