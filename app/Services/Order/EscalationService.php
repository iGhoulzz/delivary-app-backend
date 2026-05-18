<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Enums\OrderActorType;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class EscalationService
{
    public function __construct(private readonly StateTransitionService $transitions) {}

    public function runSweep(): int
    {
        return (int) Cache::lock('orders:escalation:sweep', 90)->block(5, function (): int {
            $processed = 0;

            Order::query()
                ->broadcasting()
                ->orderBy('awaiting_driver_at')
                ->chunkById(100, function ($orders) use (&$processed): void {
                    foreach ($orders as $order) {
                        $processed += $this->process($order);
                    }
                });

            return $processed;
        });
    }

    public function process(Order $order): int
    {
        if ($order->status !== OrderStatus::AwaitingDriver) {
            return 0;
        }

        return DB::transaction(function () use ($order): int {
            $order = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status !== OrderStatus::AwaitingDriver) {
                return 0;
            }

            $elapsedMinutes = $this->elapsedMinutes($order);
            $noDriverAfter = (int) PlatformSetting::get('broadcast.no_driver_after_minutes', 10);
            $tier3After = (int) PlatformSetting::get('broadcast.tier_3_after_minutes', 6);
            $tier2After = (int) PlatformSetting::get('broadcast.tier_2_after_minutes', 3);

            if ($elapsedMinutes >= $noDriverAfter) {
                $this->transitions->transition(
                    order: $order,
                    to: OrderStatus::NoDriverAvailable,
                    actorType: OrderActorType::System,
                    metadata: [
                        'event' => 'broadcast_timeout',
                        'elapsed_minutes' => $elapsedMinutes,
                    ],
                );

                return 1;
            }

            if ($elapsedMinutes >= $tier3After && $order->search_radius_tier < 3) {
                $this->applyTier($order, 3, (int) PlatformSetting::get('broadcast.tier_3_surcharge_percent', 50));

                return 1;
            }

            if ($elapsedMinutes >= $tier2After && $order->search_radius_tier < 2) {
                $this->applyTier($order, 2, (int) PlatformSetting::get('broadcast.tier_2_surcharge_percent', 20));

                return 1;
            }

            return 0;
        });
    }

    private function elapsedMinutes(Order $order): int
    {
        $startedAt = $order->awaiting_driver_at
            ?? $order->status_changed_at
            ?? $order->created_at
            ?? now();

        return (int) floor($startedAt->diffInSeconds(now()) / 60);
    }

    private function applyTier(Order $order, int $tier, int $surchargePercent): void
    {
        $multiplier = bcadd('1.00', bcdiv((string) $surchargePercent, '100', 4), 4);

        $order->forceFill([
            'search_radius_tier' => $tier,
            'delivery_fee_surcharge_percent' => $surchargePercent,
            'delivery_fee' => bcmul((string) $order->delivery_fee_base, $multiplier, 2),
        ])->save();
    }
}
