<?php

declare(strict_types=1);

namespace App\Http\Resources\Order;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class QuoteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array{quote_token: string, expires_at: string, pricing: array<string, mixed>} $r */
        $r = (array) $this->resource;

        return [
            'quote_token' => $r['quote_token'],
            'expires_at' => $r['expires_at'],
            'pricing' => [
                'region' => [
                    'id' => $r['pricing']['region_id'],
                    'name' => $r['pricing']['region_name'],
                ],
                'distance_km' => $r['pricing']['distance_km'],
                'delivery_fee_base' => $r['pricing']['delivery_fee_base'],
                'delivery_fee' => $r['pricing']['delivery_fee'],
                'delivery_fee_surcharge_percent' => $r['pricing']['delivery_fee_surcharge_percent'],
                'item_price' => $r['pricing']['item_price'],
                'commission_rate' => $r['pricing']['commission_rate'],
                'commission_amount' => $r['pricing']['commission_amount'],
                'driver_fee_cut_rate' => $r['pricing']['driver_fee_cut_rate'],
                'driver_fee_cut_amount' => $r['pricing']['driver_fee_cut_amount'],
                'cash_collected_at_delivery' => $r['pricing']['cash_collected_at_delivery'],
            ],
        ];
    }
}
