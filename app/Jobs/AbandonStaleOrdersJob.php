<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\PlatformSetting;
use App\Services\Order\FailedDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AbandonStaleOrdersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function handle(FailedDeliveryService $failures): void
    {
        Cache::lock('orders:abandon:sweep', 90)->block(5, function () use ($failures): void {
            $cutoff = now()->subDays((int) PlatformSetting::get('storage.abandonment_days', 30));
            $abandoned = 0;

            Order::query()
                ->where('status', OrderStatus::AtOffice->value)
                ->whereHas('officeInventory', fn ($query) => $query->where('received_at', '<=', $cutoff))
                ->with('officeInventory')
                ->cursor()
                ->each(function (Order $order) use ($failures, &$abandoned): void {
                    try {
                        if ($failures->abandonStale($order)) {
                            $abandoned++;
                        }
                    } catch (Throwable $exception) {
                        Log::warning('Abandon-stale job failed for order', [
                            'order_id' => $order->id,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                });

            Log::info('AbandonStaleOrdersJob complete', ['abandoned_count' => $abandoned]);
        });
    }
}
