<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Driver\Settlement;

use App\Http\Controllers\Controller;
use App\Http\Resources\Settlement\SettlementResource;
use App\Models\Settlement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListSettlementsController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $driver = $request->user();
        abort_unless($driver?->hasRole('driver'), 403);

        $settlements = Settlement::query()
            ->forDriver($driver->id)
            ->with(['office', 'processedByStaff'])
            ->latest('created_at')
            ->paginate(20);

        return SettlementResource::collection($settlements);
    }
}
