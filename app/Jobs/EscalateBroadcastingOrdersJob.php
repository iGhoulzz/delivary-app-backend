<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Order\EscalationService;

final class EscalateBroadcastingOrdersJob
{
    public function handle(EscalationService $escalation): void
    {
        $escalation->runSweep();
    }
}
