<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Driver\Settlement;

use App\Http\Controllers\Controller;
use App\Http\Resources\Settlement\SettlementResource;
use App\Models\Settlement;
use Illuminate\Http\Request;

final class ShowSettlementController extends Controller
{
    public function __invoke(Request $request, Settlement $settlement): SettlementResource
    {
        $driver = $request->user();
        abort_unless($driver?->can('viewByDriver', $settlement), 403);

        $settlement->load(['office', 'processedByStaff', 'orders']);

        return new SettlementResource($settlement);
    }
}
