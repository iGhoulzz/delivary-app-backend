<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Models\OfficeInventory;
use App\Models\PlatformSetting;
use Illuminate\Support\Carbon;

final class StorageFeeCalculator
{
    public function compute(OfficeInventory $inventory, ?Carbon $now = null): string
    {
        $now ??= now();
        $graceDays = (int) PlatformSetting::get('storage.grace_days', 5);
        $dailyFee = (string) PlatformSetting::get('storage.daily_fee', 1.00);

        $daysHeld = (int) floor($inventory->received_at->diffInSeconds($now) / 86400);
        $billableDays = max(0, $daysHeld - $graceDays);

        return bcmul((string) $billableDays, $dailyFee, 2);
    }
}
