<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Order;

use App\Enums\ItemSize;
use App\Enums\OrderType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\QuoteOrderRequest;
use App\Http\Resources\Order\QuoteResource;
use App\Services\Order\QuoteService;

final class QuoteController extends Controller
{
    public function __construct(private readonly QuoteService $quotes) {}

    public function __invoke(QuoteOrderRequest $req): QuoteResource
    {
        $result = $this->quotes->quote(
            orderType: OrderType::from((string) $req->input('order_type')),
            pickupLat: (float) $req->input('pickup_location.lat'),
            pickupLng: (float) $req->input('pickup_location.lng'),
            receiverLat: (float) $req->input('receiver_location.lat'),
            receiverLng: (float) $req->input('receiver_location.lng'),
            itemSize: ItemSize::from((string) $req->input('item_size')),
            itemPrice: $req->resolvedItemPrice(),
            deliveryFeePayer: $req->resolvedPayer(),
        );

        return new QuoteResource($result);
    }
}
