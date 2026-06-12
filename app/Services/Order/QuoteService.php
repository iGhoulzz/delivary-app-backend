<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Enums\ItemSize;
use App\Enums\OrderType;
use App\Models\PlatformSetting;
use App\Support\QuoteToken;
use App\ValueObjects\MerchantOrderContext;

final class QuoteService
{
    public function __construct(private readonly PricingService $pricing) {}

    /**
     * @return array{
     *   quote_token: string,
     *   expires_at: string,
     *   pricing: array<string, mixed>
     * }
     */
    public function quote(
        OrderType $orderType,
        float $pickupLat,
        float $pickupLng,
        float $receiverLat,
        float $receiverLng,
        ItemSize $itemSize,
        string $itemPrice,
        string $deliveryFeePayer,
        ?MerchantOrderContext $merchant = null,
    ): array {
        $paymentMethod = 'cash'; // MVP: cash only; wallet pre-pay is future

        $pricing = $this->pricing->compute(
            $orderType,
            $pickupLat, $pickupLng,
            $receiverLat, $receiverLng,
            $itemSize,
            $itemPrice,
            $deliveryFeePayer,
            $paymentMethod,
            $merchant,
        );

        $ttl = (int) PlatformSetting::get('quote.ttl_seconds', 300);
        $expiresAt = time() + $ttl;

        $payload = [
            'order_type' => $orderType->value,
            'pickup_lat' => $pickupLat, 'pickup_lng' => $pickupLng,
            'receiver_lat' => $receiverLat, 'receiver_lng' => $receiverLng,
            'item_size' => $itemSize->value,
            'item_price' => $pricing['item_price'],
            'delivery_fee_payer' => $deliveryFeePayer,
            'region_id' => $pricing['region_id'],
            'delivery_fee_base' => $pricing['delivery_fee_base'],
            'commission_amount' => $pricing['commission_amount'],
            'driver_fee_cut_amount' => $pricing['driver_fee_cut_amount'],
            // Rates (not just amounts) so a merchant override change is detectable
            // at re-verification even when item_price = 0 (amount stays 0).
            'commission_rate' => $pricing['commission_rate'],
            'driver_fee_cut_rate' => $pricing['driver_fee_cut_rate'],
            'merchant_profile_id' => $merchant?->merchantProfileId,
            'expires_at' => $expiresAt,
        ];

        return [
            'quote_token' => QuoteToken::sign($payload),
            'expires_at' => gmdate('c', $expiresAt),
            'pricing' => $pricing,
        ];
    }
}
