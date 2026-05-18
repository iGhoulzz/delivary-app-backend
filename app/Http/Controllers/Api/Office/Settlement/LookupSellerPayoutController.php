<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Office\Settlement;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settlement\LookupSellerPayoutRequest;
use App\Http\Resources\Settlement\SellerEarningResource;
use App\Models\SellerEarning;
use App\Models\SellerPayout;
use App\Models\User;
use App\Services\Settlement\SellerPayoutService;
use Illuminate\Http\JsonResponse;

final class LookupSellerPayoutController extends Controller
{
    public function __construct(private readonly SellerPayoutService $payouts)
    {
    }

    public function __invoke(LookupSellerPayoutRequest $request): JsonResponse
    {
        $staff = $request->user();
        abort_unless($staff !== null, 401);
        abort_unless($staff->can('lookupSeller', SellerPayout::class), 403);

        $seller = $this->resolveSeller($request);
        if ($seller === null) {
            return response()->json([
                'error' => 'SELLER_NOT_FOUND',
                'message' => 'No seller matched the provided phone or public_id.',
            ], 404);
        }

        $earnings = $this->payouts->availableEarningsFor($seller);
        $total = $earnings->reduce(
            static fn (string $carry, SellerEarning $e): string => bcadd($carry, (string) $e->amount, 2),
            '0.00',
        );

        return response()->json([
            'seller' => [
                'id' => $seller->public_id,
                'name' => $seller->full_name ?? $seller->name,
                'phone' => $seller->phone_number,
            ],
            'available_total' => $total,
            'available_count' => $earnings->count(),
            'earnings' => SellerEarningResource::collection($earnings)->resolve(),
        ]);
    }

    private function resolveSeller(LookupSellerPayoutRequest $request): ?User
    {
        if ($publicId = $request->input('public_id')) {
            return User::query()->where('public_id', $publicId)->first();
        }
        if ($phone = $request->input('phone')) {
            return User::query()->where('phone_number', $phone)->first();
        }

        return null;
    }
}
