<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Settlement;

use App\Http\Controllers\Controller;
use App\Http\Resources\Settlement\SettlementResource;
use App\Models\Settlement;

final class ShowSettlementController extends Controller
{
    public function __invoke(Settlement $settlement): SettlementResource
    {
        $settlement->load(['driver', 'office', 'processedByStaff', 'orders']);

        return new SettlementResource($settlement);
    }
}
