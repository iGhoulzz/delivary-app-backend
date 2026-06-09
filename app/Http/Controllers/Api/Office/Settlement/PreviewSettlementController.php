<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Office\Settlement;

use App\Http\Controllers\Controller;
use App\Http\Resources\Settlement\SettlementPreviewResource;
use App\Models\Settlement;
use App\Models\User;
use App\Services\Settlement\SettlementService;
use Illuminate\Http\Request;

final class PreviewSettlementController extends Controller
{
    public function __construct(private readonly SettlementService $settlements) {}

    public function __invoke(Request $request, string $driverPublicId): SettlementPreviewResource
    {
        $staff = $request->user();
        abort_unless($staff !== null, 401);

        $driver = User::query()
            ->where('public_id', $driverPublicId)
            ->whereHas('roles', fn ($q) => $q->where('name', 'driver'))
            ->first();

        abort_unless($driver !== null, 404, 'Driver not found.');
        abort_unless($staff->can('previewForDriver', [Settlement::class, $driver]), 403);

        return new SettlementPreviewResource($this->settlements->preview($driver));
    }
}
