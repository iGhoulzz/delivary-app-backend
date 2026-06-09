<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Office\Settlement;

use App\Exceptions\Settlement\PayoutValidationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settlement\ProcessSellerPayoutRequest;
use App\Http\Resources\Settlement\SellerPayoutResource;
use App\Models\OfficeLocation;
use App\Models\SellerPayout;
use App\Models\User;
use App\Services\Settlement\SellerPayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

final class ProcessSellerPayoutController extends Controller
{
    public function __construct(private readonly SellerPayoutService $payouts) {}

    public function __invoke(ProcessSellerPayoutRequest $request): JsonResponse|SellerPayoutResource
    {
        $staff = $request->user();
        abort_unless($staff !== null, 401);
        abort_unless($staff->can('process', SellerPayout::class), 403);

        $seller = User::query()->where('public_id', $request->sellerPublicId())->first();
        if ($seller === null) {
            return response()->json([
                'error' => 'SELLER_NOT_FOUND',
                'message' => 'Seller not found.',
            ], 404);
        }

        $office = $this->resolveStaffOffice($staff);
        if ($office === null) {
            return response()->json([
                'error' => 'OFFICE_NOT_ASSIGNED',
                'message' => 'Staff has no active office assignment.',
            ], 403);
        }

        try {
            $payout = $this->payouts->process(
                seller: $seller,
                staff: $staff,
                office: $office,
                earningPublicIds: Collection::make($request->earningPublicIds()),
                totalForSanityCheck: $request->totalAmount(),
                notes: $request->input('notes'),
            );
        } catch (PayoutValidationException $e) {
            return response()->json([
                'error' => $e->errorCode()->value,
                'message' => $e->getMessage(),
            ], $e->errorCode()->httpStatus());
        }

        return new SellerPayoutResource($payout);
    }

    private function resolveStaffOffice(User $staff): ?OfficeLocation
    {
        return $staff->officeStaffAssignments()->active()->first()?->office;
    }
}
