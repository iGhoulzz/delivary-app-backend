<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Enums\ItemSize;
use App\Enums\OrderErrorCode;
use App\Enums\OrderType;
use App\Exceptions\Order\OrderDomainException;
use App\Models\PlatformSetting;
use App\Models\Region;
use Illuminate\Support\Facades\DB;

final class PricingService
{
    /**
     * Pure computation. No DB writes.
     *
     * @return array{
     *   region_id: int,
     *   region_name: string,
     *   distance_km: string,
     *   delivery_fee_base: string,
     *   delivery_fee: string,
     *   delivery_fee_surcharge_percent: int,
     *   item_price: string,
     *   commission_rate: string,
     *   commission_amount: string,
     *   driver_fee_cut_rate: string,
     *   driver_fee_cut_amount: string,
     *   cash_collected_at_delivery: string
     * }
     */
    public function compute(
        OrderType $orderType,
        float $pickupLat,
        float $pickupLng,
        float $receiverLat,
        float $receiverLng,
        ItemSize $itemSize,
        string $itemPrice,              // "0.00" for standard_delivery
        string $deliveryFeePayer,       // 'sender' | 'receiver'
        string $paymentMethod,          // 'cash' | 'wallet'
    ): array {
        $region = $this->resolveRegion($pickupLng, $pickupLat);

        $distanceKm = $this->straightLineKm($pickupLng, $pickupLat, $receiverLng, $receiverLat);

        $modifiers = (array) PlatformSetting::get('pricing.item_size_modifiers', []);
        $sizeMod = (string) ($modifiers[$itemSize->value] ?? 0);

        $freeKm = (string) PlatformSetting::get('pricing.free_km', 999);
        $perKmRate = (string) PlatformSetting::get('pricing.per_km_rate', 0);

        $kmCharged = bccomp($distanceKm, $freeKm, 1) === 1
            ? bcsub($distanceKm, $freeKm, 1)
            : '0.0';
        $distanceFee = bcmul($kmCharged, $perKmRate, 2);

        $base = bcadd(
            bcadd((string) $region->base_fee, $sizeMod, 2),
            $distanceFee,
            2
        );

        // Surcharge starts at 0 at creation; escalation job bumps it later.
        $surchargePercent = 0;
        $fee = $base; // = base × (1 + 0/100)

        // P2P commission
        $commissionRate = $orderType === OrderType::P2pSale
            ? (string) PlatformSetting::get('pricing.item_commission_rate', 0)
            : '0';
        $commissionAmount = bcmul($itemPrice, $commissionRate, 2);

        $driverCutRate = (string) PlatformSetting::get('pricing.driver_fee_cut_rate', 0.02);
        $driverCutAmount = bcmul($base, $driverCutRate, 2);

        $cashAtDelivery = bcadd(
            $itemPrice,
            ($deliveryFeePayer === 'receiver' && $paymentMethod === 'cash') ? $fee : '0',
            2
        );

        return [
            'region_id' => $region->id,
            'region_name' => $region->name,
            'distance_km' => $distanceKm,
            'delivery_fee_base' => $base,
            'delivery_fee' => $fee,
            'delivery_fee_surcharge_percent' => $surchargePercent,
            'item_price' => bcadd($itemPrice, '0', 2),  // normalise to 2dp
            'commission_rate' => $commissionRate,
            'commission_amount' => $commissionAmount,
            'driver_fee_cut_rate' => $driverCutRate,
            'driver_fee_cut_amount' => $driverCutAmount,
            'cash_collected_at_delivery' => $cashAtDelivery,
        ];
    }

    private function resolveRegion(float $lng, float $lat): Region
    {
        $row = DB::selectOne(
            'SELECT regions.id, regions.name, regions.base_fee::text AS base_fee
               FROM regions
               JOIN service_areas ON service_areas.id = regions.service_area_id
              WHERE regions.is_active = true
                AND service_areas.is_active = true
                AND ST_Contains(regions.boundary::geometry, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geometry)
              LIMIT 1',
            [$lng, $lat]
        );

        if ($row === null) {
            throw new OrderDomainException(
                OrderErrorCode::PickupOutOfServiceArea,
                trans('order_messages.pickup_out_of_service_area'),
            );
        }

        $region = new Region;
        $region->id = (int) $row->id;
        $region->name = (string) $row->name;
        $region->base_fee = (string) $row->base_fee;

        return $region;
    }

    private function straightLineKm(float $lng1, float $lat1, float $lng2, float $lat2): string
    {
        $meters = (float) DB::selectOne(
            'SELECT ST_Distance(ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) AS d',
            [$lng1, $lat1, $lng2, $lat2]
        )->d;

        return bcdiv((string) round($meters, 0), '1000', 1);
    }
}
