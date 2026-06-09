<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Settlement;

use App\Exceptions\Settlement\SettlementNotReversibleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settlement\ReverseSettlementRequest;
use App\Http\Resources\Settlement\SettlementResource;
use App\Models\Settlement;
use App\Services\Settlement\SettlementReversalService;
use Illuminate\Http\JsonResponse;

final class ReverseSettlementController extends Controller
{
    public function __construct(private readonly SettlementReversalService $reversal) {}

    public function __invoke(
        ReverseSettlementRequest $request,
        Settlement $settlement,
    ): JsonResponse|SettlementResource {
        $admin = $request->user();
        abort_unless($admin !== null, 401);
        abort_unless($admin->can('reverse', $settlement), 403);

        try {
            $correcting = $this->reversal->reverse($settlement, $admin, $request->reason());
        } catch (SettlementNotReversibleException $e) {
            return response()->json([
                'error' => $e->errorCode()->value,
                'message' => $e->getMessage(),
            ], $e->errorCode()->httpStatus());
        }

        return new SettlementResource($correcting);
    }
}
