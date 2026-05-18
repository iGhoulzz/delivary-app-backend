<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Me\Settlement;

use App\Http\Controllers\Controller;
use App\Http\Resources\Settlement\SellerPayoutResource;
use App\Models\SellerPayout;
use Illuminate\Http\Request;

final class ShowSellerPayoutController extends Controller
{
    public function __invoke(Request $request, SellerPayout $sellerPayout): SellerPayoutResource
    {
        $user = $request->user();
        abort_unless($user?->can('viewBySeller', $sellerPayout), 403);

        $sellerPayout->load(['office', 'paidByStaff', 'orders']);

        return new SellerPayoutResource($sellerPayout);
    }
}
