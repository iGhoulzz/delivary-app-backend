<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Driver;

use App\Enums\DriverAccountBucket;
use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\AdjustAccountRequest;
use App\Http\Resources\Driver\DriverAccountTransactionResource;
use App\Http\Resources\DriverAccountResource;
use App\Models\User;
use App\Services\Driver\DriverAccountLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin read of a driver's 3-bucket account + recent ledger, plus an audited
 * manual adjustment. Mirrors the driver-self AccountController, re-exposed
 * under role:admin for any driver.
 */
final class AccountController extends Controller
{
    public function __construct(private readonly DriverAccountLedgerService $ledger) {}

    public function show(Request $request, User $driverUser): JsonResponse
    {
        $account = $driverUser->driverAccount;

        if ($account === null) {
            return response()->json(['error' => 'no_driver_account'], 404);
        }

        $transactions = $driverUser->driverAccountTransactions()
            ->latest()
            ->limit(30)
            ->get();

        return response()->json([
            'account' => (new DriverAccountResource($account))->resolve($request),
            'transactions' => DriverAccountTransactionResource::collection($transactions)->resolve($request),
        ]);
    }

    public function adjust(AdjustAccountRequest $request, User $driverUser): JsonResponse
    {
        if ($driverUser->driverAccount === null) {
            return response()->json(['error' => 'no_driver_account'], 404);
        }

        $this->ledger->applyManualAdjustment(
            $driverUser,
            DriverAccountBucket::from((string) $request->input('bucket')),
            (string) $request->input('amount'),
            (int) $request->user()->id,
            is_string($request->input('note')) ? $request->input('note') : null,
        );

        return response()->json([
            'account' => (new DriverAccountResource($driverUser->driverAccount()->first()))->resolve($request),
        ]);
    }
}
