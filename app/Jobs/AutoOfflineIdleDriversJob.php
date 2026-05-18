<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Driver\AutoOfflineService;

final class AutoOfflineIdleDriversJob
{
    public function handle(AutoOfflineService $autoOffline): void
    {
        $autoOffline->runSweep();
    }
}
