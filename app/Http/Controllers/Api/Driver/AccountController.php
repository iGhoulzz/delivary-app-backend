<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriverAccountResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AccountController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $account = $user->driverAccount;
        if ($account === null) {
            return response()->json(['error' => 'no_driver_account'], 404);
        }
        $transactions = $user->driverAccountTransactions()
            ->latest()
            ->limit(30)
            ->get(['id', 'bucket', 'amount', 'reason', 'balance_after', 'created_at']);

        return response()->json([
            'account' => (new DriverAccountResource($account))->resolve($request),
            'transactions' => $transactions,
        ]);
    }
}
