<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Me\Settlement;

use App\Http\Controllers\Controller;
use App\Http\Resources\Settlement\SellerPayoutResource;
use App\Models\SellerPayout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListSellerPayoutsController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $payouts = SellerPayout::query()
            ->forUser($user->id)
            ->with(['office', 'paidByStaff'])
            ->latest('paid_at')
            ->paginate(20);

        return SellerPayoutResource::collection($payouts);
    }
}
