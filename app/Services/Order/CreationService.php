<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Enums\DeliveryFeePaymentMethod;
use App\Enums\DeliveryFeeStatus;
use App\Enums\ItemSize;
use App\Enums\MerchantStatus;
use App\Enums\OrderActorType;
use App\Enums\OrderErrorCode;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ReceiverType;
use App\Exceptions\Order\OrderDomainException;
use App\Exceptions\Order\QuoteMismatchException;
use App\Models\GuestRecipient;
use App\Models\Order;
use App\Models\User;
use App\Support\QuoteToken;
use App\ValueObjects\MerchantOrderContext;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreationService
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly QuoteService $quotes,
        private readonly CodeVerificationService $codes,
        private readonly StateTransitionService $transitions,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(
        User $sender,
        array $input,
        ?string $idempotencyKey = null,
        ?MerchantOrderContext $merchant = null,
    ): Order {
        $bodyHash = $this->bodyHash($input);

        if ($idempotencyKey !== null) {
            $cached = Cache::get($this->idempotencyCacheKey($sender, $idempotencyKey));

            if (is_array($cached) && isset($cached['order_id'])) {
                if (($cached['body_hash'] ?? null) !== $bodyHash) {
                    throw new OrderDomainException(
                        OrderErrorCode::IdempotencyConflict,
                        trans('order_messages.idempotency_conflict'),
                    );
                }

                return Order::query()->findOrFail($cached['order_id']);
            }
        }

        $tokenPayload = $this->verifiedQuotePayload((string) $input['quote_token']);

        $orderType = OrderType::from((string) $input['order_type']);
        $itemSize = ItemSize::from((string) $input['item_size']);

        // Item price applies to SALE orders (p2p_sale OR merchant_delivery).
        $isSale = $orderType === OrderType::P2pSale || $orderType === OrderType::MerchantDelivery;
        $itemPrice = $isSale
            ? bcadd((string) ($input['item_price'] ?? '0'), '0', 2)
            : '0.00';
        // P2P and merchant orders are always receiver-paid (spec §4.4); standard
        // delivery lets the caller choose.
        $payer = match (true) {
            $orderType === OrderType::P2pSale, $merchant !== null => 'receiver',
            default => (string) ($input['delivery_fee_payer'] ?? 'sender'),
        };

        $this->assertQuoteMatchesRequest($tokenPayload, $input, $orderType, $itemSize, $itemPrice, $payer, $merchant);

        $fresh = $this->pricing->compute(
            $orderType,
            (float) $input['pickup_location']['lat'],
            (float) $input['pickup_location']['lng'],
            (float) $input['receiver_location']['lat'],
            (float) $input['receiver_location']['lng'],
            $itemSize,
            $itemPrice,
            $payer,
            DeliveryFeePaymentMethod::Cash->value,
            $merchant,
        );

        $this->assertQuotePriceStillCurrent($tokenPayload, $fresh, $orderType, $input, $itemSize, $itemPrice, $payer, $merchant);
        $this->assertSenderCanCreateForReceiver($sender, (string) $input['receiver_phone']);

        if ($merchant === null) {
            $this->assertNotMerchantFlow($sender);
        }

        $receiverUser = User::query()
            ->where('phone_number', (string) $input['receiver_phone'])
            ->first();
        $receiverType = $receiverUser === null ? ReceiverType::Guest : ReceiverType::RegisteredUser;
        $codePair = $this->codes->generatePair();

        return DB::transaction(function () use (
            $sender,
            $input,
            $idempotencyKey,
            $bodyHash,
            $orderType,
            $itemSize,
            $itemPrice,
            $payer,
            $fresh,
            $receiverUser,
            $receiverType,
            $codePair,
            $merchant,
        ): Order {
            $guest = $receiverType === ReceiverType::Guest
                ? $this->touchGuestRecipient((string) $input['receiver_phone'], (string) $input['receiver_name'])
                : null;

            $order = Order::create([
                'order_type' => $orderType->value,
                'status' => OrderStatus::Created->value,
                'status_changed_at' => now(),

                'merchant_profile_id' => $merchant?->merchantProfileId,
                'sender_user_id' => $sender->id,
                // Merchant orders snapshot the business identity, not the owner's personal details.
                'sender_phone' => $merchant !== null
                    ? (string) $merchant->contactPhone
                    : (string) $sender->phone_number,
                'sender_name' => $merchant !== null
                    ? $merchant->businessName
                    : $sender->fullName(),

                'pickup_address' => (string) $input['pickup_address'],
                'pickup_location' => Point::makeGeodetic(
                    (float) $input['pickup_location']['lat'],
                    (float) $input['pickup_location']['lng'],
                ),
                // Snapshot the resolved pickup region + office (Critical Rule 1) so
                // finance by-office attribution reads order-time truth, not today's map.
                'pickup_region_id' => $fresh['region_id'],
                'pickup_office_id' => $fresh['office_id'],
                'pickup_notes' => $input['pickup_notes'] ?? null,
                'pickup_code' => $codePair['pickup'],
                'pickup_code_attempts' => 0,

                'receiver_type' => $receiverType->value,
                'receiver_user_id' => $receiverUser?->id,
                'receiver_guest_id' => $guest?->id,
                'receiver_phone' => (string) $input['receiver_phone'],
                'receiver_name' => (string) $input['receiver_name'],
                'receiver_address' => (string) $input['receiver_address'],
                'receiver_location' => Point::makeGeodetic(
                    (float) $input['receiver_location']['lat'],
                    (float) $input['receiver_location']['lng'],
                ),
                'receiver_notes' => $input['receiver_notes'] ?? null,
                'delivery_code' => $codePair['delivery'],
                'delivery_code_attempts' => 0,

                'driver_assignment_attempts' => 0,
                'search_radius_tier' => 1,

                'item_description' => (string) $input['item_description'],
                'item_size' => $itemSize->value,
                'item_weight_kg' => $input['item_weight_kg'] ?? null,
                'item_value' => $input['item_value'] ?? null,

                'item_price' => $itemPrice,
                'commission_rate' => $fresh['commission_rate'],
                'commission_amount' => $fresh['commission_amount'],
                'delivery_fee_base' => $fresh['delivery_fee_base'],
                'delivery_fee_surcharge_percent' => 0,
                'delivery_fee' => $fresh['delivery_fee'],
                'driver_fee_cut_rate' => $fresh['driver_fee_cut_rate'],
                'driver_fee_cut_amount' => $fresh['driver_fee_cut_amount'],
                'delivery_fee_payer' => $payer,
                'delivery_fee_payment_method' => DeliveryFeePaymentMethod::Cash->value,
                'delivery_fee_status' => DeliveryFeeStatus::Unpaid->value,
            ]);

            $this->transitions->transition(
                order: $order,
                to: OrderStatus::AwaitingDriver,
                actorType: OrderActorType::User,
                actorId: $sender->id,
                metadata: [
                    'event' => 'order_created',
                    'region_id' => $fresh['region_id'],
                    'distance_km' => $fresh['distance_km'],
                ],
            );

            if ($idempotencyKey !== null) {
                Cache::put(
                    $this->idempotencyCacheKey($sender, $idempotencyKey),
                    ['order_id' => $order->id, 'body_hash' => $bodyHash],
                    now()->addDay(),
                );
            }

            return $order->refresh()->load(['driver.driverProfile']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function verifiedQuotePayload(string $token): array
    {
        try {
            $verified = QuoteToken::verify($token);
        } catch (InvalidArgumentException) {
            throw new OrderDomainException(
                OrderErrorCode::InvalidQuoteToken,
                trans('order_messages.invalid_quote_token'),
            );
        }

        if ($verified['expired']) {
            throw new OrderDomainException(
                OrderErrorCode::QuoteExpired,
                trans('order_messages.quote_expired'),
            );
        }

        return $verified['payload'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $input
     */
    private function assertQuoteMatchesRequest(
        array $payload,
        array $input,
        OrderType $orderType,
        ItemSize $itemSize,
        string $itemPrice,
        string $payer,
        ?MerchantOrderContext $merchant = null,
    ): void {
        $matches = (string) ($payload['order_type'] ?? '') === $orderType->value
            && (string) ($payload['item_size'] ?? '') === $itemSize->value
            && bccomp((string) ($payload['item_price'] ?? ''), $itemPrice, 2) === 0
            && (string) ($payload['delivery_fee_payer'] ?? '') === $payer
            // Token identity: the quote must have been issued for this merchant
            // (null for the customer path). Mismatch = wrong/tampered token (400).
            && (int) ($payload['merchant_profile_id'] ?? 0) === (int) ($merchant?->merchantProfileId ?? 0)
            && $this->sameCoordinate($payload['pickup_lat'] ?? null, $input['pickup_location']['lat'])
            && $this->sameCoordinate($payload['pickup_lng'] ?? null, $input['pickup_location']['lng'])
            && $this->sameCoordinate($payload['receiver_lat'] ?? null, $input['receiver_location']['lat'])
            && $this->sameCoordinate($payload['receiver_lng'] ?? null, $input['receiver_location']['lng']);

        if (! $matches) {
            throw new OrderDomainException(
                OrderErrorCode::InvalidQuoteToken,
                trans('order_messages.invalid_quote_token'),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $fresh
     * @param  array<string, mixed>  $input
     */
    private function assertQuotePriceStillCurrent(
        array $payload,
        array $fresh,
        OrderType $orderType,
        array $input,
        ItemSize $itemSize,
        string $itemPrice,
        string $payer,
        ?MerchantOrderContext $merchant = null,
    ): void {
        $changed = (int) $fresh['region_id'] !== (int) ($payload['region_id'] ?? 0)
            || bccomp((string) $fresh['delivery_fee_base'], (string) ($payload['delivery_fee_base'] ?? ''), 2) !== 0
            || bccomp((string) $fresh['commission_amount'], (string) ($payload['commission_amount'] ?? ''), 2) !== 0
            || bccomp((string) $fresh['driver_fee_cut_amount'], (string) ($payload['driver_fee_cut_amount'] ?? ''), 2) !== 0
            // Compare RATES too (4dp) so a merchant override edit mid-quote is a
            // price-change (409) even when item_price = 0 keeps the amount at 0.
            || bccomp((string) $fresh['commission_rate'], (string) ($payload['commission_rate'] ?? ''), 4) !== 0
            || bccomp((string) $fresh['driver_fee_cut_rate'], (string) ($payload['driver_fee_cut_rate'] ?? ''), 4) !== 0;

        if (! $changed) {
            return;
        }

        throw new QuoteMismatchException($this->quotes->quote(
            $orderType,
            (float) $input['pickup_location']['lat'],
            (float) $input['pickup_location']['lng'],
            (float) $input['receiver_location']['lat'],
            (float) $input['receiver_location']['lng'],
            $itemSize,
            $itemPrice,
            $payer,
            $merchant,
        ));
    }

    private function assertSenderCanCreateForReceiver(User $sender, string $receiverPhone): void
    {
        if ($receiverPhone === (string) $sender->phone_number) {
            throw new OrderDomainException(
                OrderErrorCode::SenderIsReceiver,
                trans('order_messages.sender_is_receiver'),
            );
        }
    }

    private function assertNotMerchantFlow(User $sender): void
    {
        $merchant = $sender->merchantProfile;

        if ($merchant !== null && $merchant->status === MerchantStatus::Active) {
            throw new OrderDomainException(
                OrderErrorCode::MerchantUseMerchantFlow,
                trans('order_messages.merchant_use_merchant_flow'),
            );
        }
    }

    private function touchGuestRecipient(string $phone, string $name): GuestRecipient
    {
        $guest = GuestRecipient::query()->firstOrNew(['phone_number' => $phone]);
        [$firstName, $lastName] = $this->splitName($name);

        if (! $guest->exists) {
            $guest->first_received_at = now();
            $guest->first_name = $firstName;
            $guest->last_name = $lastName;
        }

        $guest->last_received_at = now();
        $guest->total_deliveries = ((int) $guest->total_deliveries) + 1;
        $guest->save();

        return $guest;
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2);

        return [$parts[0] ?? $name, $parts[1] ?? null];
    }

    private function sameCoordinate(mixed $left, mixed $right): bool
    {
        return abs((float) $left - (float) $right) < 0.000001;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function bodyHash(array $input): string
    {
        ksort($input);

        return hash('sha256', (string) json_encode($input));
    }

    private function idempotencyCacheKey(User $sender, string $idempotencyKey): string
    {
        return "order_idem:{$sender->id}:{$idempotencyKey}";
    }
}
