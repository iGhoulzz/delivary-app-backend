<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Settlement;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settlement\ListSellerPayoutsRequest;
use App\Http\Resources\Settlement\AdminSellerPayoutResource;
use App\Models\SellerPayout;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListSellerPayoutsController extends Controller
{
    public function __invoke(ListSellerPayoutsRequest $request): AnonymousResourceCollection
    {
        $query = SellerPayout::query()->with(['user', 'office', 'paidByStaff', 'orders']);

        if ($sellerPublicId = $request->input('seller_public_id')) {
            $sellerId = User::query()->where('public_id', $sellerPublicId)->value('id');
            $query->where('user_id', $sellerId ?? -1);
        }
        if ($officeId = $request->officeId()) {
            $query->where('office_id', $officeId);
        }
        if ($from = $request->input('from')) {
            $query->where('paid_at', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $query->where('paid_at', '<=', $to);
        }

        return AdminSellerPayoutResource::collection(
            $query->latest('paid_at')->paginate((int) $request->input('per_page', 20))
        );
    }
}
