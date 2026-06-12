<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Enums\ItemSize;
use App\Enums\OrderType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\QuoteMerchantOrderRequest;
use App\Http\Requests\Merchant\StoreMerchantOrderRequest;
use App\Http\Resources\Order\OrderResource;
use App\Http\Resources\Order\QuoteResource;
use App\Models\Order;
use App\Services\Merchant\MerchantOrderCreationService;
use App\Services\Order\QuoteService;
use App\ValueObjects\MerchantOrderContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class MerchantOrderController extends Controller
{
    public function __construct(
        private readonly MerchantOrderCreationService $merchantOrders,
        private readonly QuoteService $quotes,
    ) {}

    public function quote(QuoteMerchantOrderRequest $request, MerchantOrderCreationService $svc): QuoteResource
    {
        $profile = $svc->requireActiveProfile($request->user());
        // Resolve pickup (defaulting/validation) BEFORE pricing so a defaulted
        // pickup is what gets quoted, and a missing one throws MissingPickup.
        $resolved = $svc->resolvePickup($request->validated(), $profile);

        $result = $this->quotes->quote(
            OrderType::MerchantDelivery,
            (float) $resolved['pickup_location']['lat'],
            (float) $resolved['pickup_location']['lng'],
            (float) $resolved['receiver_location']['lat'],
            (float) $resolved['receiver_location']['lng'],
            ItemSize::from((string) $resolved['item_size']),
            bcadd((string) ($resolved['item_price'] ?? '0'), '0', 2),
            'receiver',
            MerchantOrderContext::fromProfile($profile),
        );

        return new QuoteResource($result);
    }

    public function store(StoreMerchantOrderRequest $request): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        $order = $this->merchantOrders->create(
            $request->user(),
            $request->validated(),
            is_string($idempotencyKey) && $idempotencyKey !== '' ? $idempotencyKey : null,
        );

        return (new OrderResource($order))
            ->response()
            ->setStatusCode(201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $profileId = $request->user()->merchantProfile->id;

        $orders = Order::query()
            ->forMerchant($profileId)
            ->with(['driver.driverProfile', 'returnOffice', 'officeInventory'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return OrderResource::collection($orders);
    }

    public function show(Request $request, Order $order): OrderResource
    {
        abort_unless(
            $order->merchant_profile_id === $request->user()->merchantProfile->id,
            404,
        );

        return new OrderResource(
            $order->loadMissing(['driver.driverProfile', 'returnOffice', 'officeInventory']),
        );
    }
}
