<?php

declare(strict_types=1);

namespace App\Http\Resources\Settlement;

use App\Enums\SellerEarningStatus;
use App\Models\SellerEarning;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/** @property array{seller_id: int, earnings: Collection<int, SellerEarning>} $resource */
final class SellerEarningsSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Collection<int, SellerEarning> $earnings */
        $earnings = $this->resource['earnings'];

        $byStatus = static function (SellerEarningStatus $status) use ($earnings): array {
            $rows = $earnings->where('status', $status)->values();
            $total = $rows->reduce(
                static fn (string $carry, SellerEarning $earning): string => bcadd($carry, (string) $earning->amount, 2),
                '0.00',
            );

            return [
                'count' => $rows->count(),
                'total' => $total,
                'items' => SellerEarningResource::collection($rows)->resolve(),
            ];
        };

        return [
            'pending_settlement' => $byStatus(SellerEarningStatus::PendingSettlement),
            'pending_clearance' => $byStatus(SellerEarningStatus::PendingClearance),
            'available' => $byStatus(SellerEarningStatus::Available),
            'paid_out_recent' => $byStatus(SellerEarningStatus::PaidOut),
        ];
    }
}
