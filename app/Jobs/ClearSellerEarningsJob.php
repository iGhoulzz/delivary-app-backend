<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SellerEarningStatus;
use App\Events\SellerEarningCleared;
use App\Models\PlatformSetting;
use App\Models\SellerEarning;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ClearSellerEarningsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function handle(): void
    {
        Cache::lock('seller-earnings:clear', 90)->block(5, function (): void {
            $hours = (int) PlatformSetting::get('payouts.clearance_hours', 48);
            $cutoff = now()->subHours($hours);
            $advanced = 0;

            SellerEarning::query()
                ->pendingClearance()
                ->where('cleared_at', '<=', $cutoff)
                ->cursor()
                ->each(function (SellerEarning $earning) use (&$advanced): void {
                    try {
                        $earning->status = SellerEarningStatus::Available->value;
                        $earning->available_at = now();
                        $earning->save();
                        $earning->loadMissing(['order:id,public_id,item_description']);

                        event(new SellerEarningCleared(
                            $earning,
                            $this->availableTotalForSeller($earning->seller_user_id),
                        ));

                        $advanced++;
                    } catch (Throwable $exception) {
                        Log::warning('ClearSellerEarningsJob row failed', [
                            'earning_id' => $earning->id,
                            'order_id' => $earning->order_id,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                });

            Log::info('ClearSellerEarningsJob complete', ['advanced_count' => $advanced]);
        });
    }

    private function availableTotalForSeller(int $sellerId): string
    {
        $total = SellerEarning::query()
            ->forSeller($sellerId)
            ->available()
            ->sum('amount');

        return number_format((float) $total, 2, '.', '');
    }
}
