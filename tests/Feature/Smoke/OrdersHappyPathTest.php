<?php

declare(strict_types=1);

use App\Enums\DriverActivityStatus;
use App\Enums\ItemSize;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\VehicleType;
use App\Models\DriverAccount;
use App\Models\DriverProfile;
use App\Models\Order;
use App\Models\User;
use App\Services\Driver\PresenceService;
use App\Services\Order\BroadcastService;
use App\Services\Order\ClaimService;
use App\Services\Order\CodeVerificationService;
use App\Services\Order\CreationService;
use App\Services\Order\QuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestWorld;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $world = TestWorld::create();
    $this->pickup = $world['pickup'];
    $this->dropoff = $world['dropoff'];

    $this->sender = User::factory()->create();
    $this->sender->assignRole('user');

    // Online Car driver at the pickup point, with liability headroom.
    $this->driver = makeOnlineDriverAt($this->pickup['lat'], $this->pickup['lng'], VehicleType::Car);

    $this->createOrder = function (string $receiverPhone = '+218910000111'): Order {
        $quote = app(QuoteService::class)->quote(
            OrderType::StandardDelivery,
            $this->pickup['lat'], $this->pickup['lng'],
            $this->dropoff['lat'], $this->dropoff['lng'],
            ItemSize::Small, '0.00', 'sender',
        );

        return app(CreationService::class)->create($this->sender, [
            'quote_token' => $quote['quote_token'],
            'order_type' => OrderType::StandardDelivery->value,
            'pickup_location' => $this->pickup,
            'pickup_address' => 'Smoke pickup',
            'receiver_location' => $this->dropoff,
            'receiver_address' => 'Smoke dropoff',
            'receiver_phone' => $receiverPhone,
            'receiver_name' => 'Smoke Receiver',
            'item_size' => ItemSize::Small->value,
            'item_description' => 'Smoke parcel',
            'delivery_fee_payer' => 'sender',
        ]);
    };
});

it('creates a sender-paid order in awaiting_driver with one status log', function (): void {
    $order = ($this->createOrder)();

    expect($order->status)->toBe(OrderStatus::AwaitingDriver);
    expect($order->statusLogs()->count())->toBe(1);
});

it('broadcasts the awaiting order to a nearby online driver', function (): void {
    $order = ($this->createOrder)();

    $candidates = app(BroadcastService::class)->candidatesFor($this->driver);

    expect($candidates->where('id', $order->id)->count())->toBe(1);
});

it('completes a standard delivery end to end and credits the driver buckets', function (): void {
    $order = ($this->createOrder)();
    $pickupCode = $order->pickup_code;
    $deliveryCode = $order->delivery_code;

    // Claim → en route to pickup.
    $order = app(ClaimService::class)->claim($this->driver, $order);
    expect($order->status)->toBe(OrderStatus::DriverEnRoutePickup);

    // Confirm pickup → auto-chains to en route to dropoff.
    $order = app(CodeVerificationService::class)->confirmPickup($this->driver, $order, 'code', $pickupCode);
    expect($order->status)->toBe(OrderStatus::DriverEnRouteDropoff);

    // Arrive → delivery in progress.
    app(PresenceService::class)->updateLocation($this->driver, $this->dropoff);
    $order = app(CodeVerificationService::class)->arrivedDropoff($this->driver, $order);
    expect($order->status)->toBe(OrderStatus::DeliveryInProgress);

    // Confirm delivery code → delivered.
    $order = app(CodeVerificationService::class)->confirmDelivery($this->driver, $order, $deliveryCode);
    expect($order->status)->toBe(OrderStatus::Delivered);

    // Money: sender-paid fee collected as cash; driver earns fee minus 2% platform cut.
    $account = DriverAccount::query()->where('driver_id', $this->driver->id)->firstOrFail();
    expect((string) $account->cash_to_deposit)->toBe('10.00');
    expect((string) $account->earnings_balance)->toBe('9.80');

    // Driver returns online after completing the delivery.
    expect(DriverProfile::query()->where('user_id', $this->driver->id)->firstOrFail()->activity_status)
        ->toBe(DriverActivityStatus::Online);
});
