<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Office\Settlement;

use App\Exceptions\Settlement\EmptySettlementException;
use App\Exceptions\Settlement\SettlementExcessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settlement\ProcessSettlementRequest;
use App\Http\Resources\Settlement\SettlementResource;
use App\Models\OfficeLocation;
use App\Models\Settlement;
use App\Models\User;
use App\Services\Settlement\SettlementService;
use Illuminate\Http\JsonResponse;

final class ProcessSettlementController extends Controller
{
    public function __construct(private readonly SettlementService $settlements)
    {
    }

    public function __invoke(ProcessSettlementRequest $request): JsonResponse|SettlementResource
    {
        $staff = $request->user();
        abort_unless($staff !== null, 401);
        abort_unless($staff->can('process', Settlement::class), 403);

        $driver = User::query()
            ->where('public_id', $request->driverPublicId())
            ->whereHas('roles', fn ($q) => $q->where('name', 'driver'))
            ->first();
        abort_unless($driver !== null, 404, 'Driver not found.');

        $office = $this->resolveStaffOffice($staff);
        abort_unless($office !== null, 403, 'Staff has no active office assignment.');

        try {
            $settlement = $this->settlements->process(
                driver: $driver,
                staff: $staff,
                office: $office,
                cashReceivedFromDriver: $request->cashReceived(),
                cashPaidToDriver: $request->cashPaid(),
                notes: $request->input('notes'),
            );
        } catch (SettlementExcessException $e) {
            return response()->json([
                'error' => $e->errorCode()->value,
                'message' => $e->getMessage(),
                'expected_net' => $e->expectedNet,
                'actual_net' => $e->actualNet,
            ], $e->errorCode()->httpStatus());
        } catch (EmptySettlementException $e) {
            return response()->json([
                'error' => $e->errorCode()->value,
                'message' => $e->getMessage(),
            ], $e->errorCode()->httpStatus());
        }

        return new SettlementResource($settlement);
    }

    private function resolveStaffOffice(User $staff): ?OfficeLocation
    {
        $assignment = $staff->officeStaffAssignments()->active()->first();

        return $assignment?->office;
    }
}
