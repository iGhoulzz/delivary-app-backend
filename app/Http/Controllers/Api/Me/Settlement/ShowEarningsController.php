<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Me\Settlement;

use App\Http\Controllers\Controller;
use App\Http\Resources\Settlement\SellerEarningsSummaryResource;
use App\Models\SellerEarning;
use Illuminate\Http\Request;

final class ShowEarningsController extends Controller
{
    public function __invoke(Request $request): SellerEarningsSummaryResource
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $earnings = SellerEarning::query()
            ->forSeller($user->id)
            ->with('order:id,public_id,item_description')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return new SellerEarningsSummaryResource([
            'earnings' => $earnings,
        ]);
    }
}
