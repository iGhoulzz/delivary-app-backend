<?php

declare(strict_types=1);

use App\Enums\DeliveryFeeStatus;
use App\Enums\DriverActivityStatus;
use App\Enums\ItemSize;
use App\Enums\OrderActorType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ReturnFault;
use App\Enums\ReturnReason;
use App\Enums\VehicleType;
use App\Exceptions\Order\OrderDomainException;
use App\Jobs\AbandonStaleOrdersJob;
use App\Models\DriverAccount;
use App\Models\DriverPresenceLog;
use App\Models\DriverProfile;
use App\Models\OfficeInventory;
use App\Models\OfficeLocation;
use App\Models\OfficeStaffAssignment;
use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Driver\AutoOfflineService;
use App\Services\Order\AdminAssignmentService;
use App\Services\Order\CodeVerificationService;
use App\Services\Order\CreationService;
use App\Services\Order\FailedDeliveryService;
use App\Services\Order\QuoteService;
use App\Services\Order\StateTransitionService;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestWorld;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $world = TestWorld::create();
    $this->region = $world['region'];
    $this->office = $world['office'];
    $this->pickup = $world['pickup'];
    $this->dropoff = $world['dropoff'];

    PlatformSetting::set('storage.grace_days', 5);
    PlatformSetting::set('storage.daily_fee', '1.00');
    PlatformSetting::set('storage.abandonment_days', 30);

    $this->sender = User::factory()->create();
    $this->sender->assignRole('user');
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->driver = makeOnlineDriverAt($this->pickup['lat'], $this->pickup['lng'], VehicleType::Car);

    $this->officeStaff = User::factory()->create();
    $this->officeStaff->assignRole('office_staff');
    OfficeStaffAssignment::create([
        'user_id' => $this->officeStaff->id,
        'office_id' => $this->office->id,
        'is_manager' => true,
        'assigned_at' => now(),
    ]);

    $this->createOrder = function (string $receiverPhone, string $payer = 'sender'): Order {
        $quote = app(QuoteService::class)->quote(
            OrderType::StandardDelivery,
            $this->pickup['lat'], $this->pickup['lng'],
            $this->dropoff['lat'], $this->dropoff['lng'],
            ItemSize::Small, '0.00', $payer,
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
            'delivery_fee_payer' => $payer,
        ]);
    };

    $this->orderToPostPickup = function (string $receiverPhone, string $payer = 'receiver'): Order {
        $order = app(AdminAssignmentService::class)->assign($this->admin, ($this->createOrder)($receiverPhone, $payer), $this->driver, force: true);

        return app(CodeVerificationService::class)->confirmPickup($this->driver, $order, 'code', $order->pickup_code);
    };
});

it('driver-fault failure returns to office without crediting driver earnings', function (): void {
    $order = ($this->orderToPostPickup)('+218910000301', 'receiver');
    $before = (string) DriverAccount::query()->where('driver_id', $this->driver->id)->value('earnings_balance');

    $order = app(FailedDeliveryService::class)->markDeliveryFailedByDriver($this->driver, $order, ReturnReason::DriverFault, 'fault');
    expect($order->status)->toBe(OrderStatus::ReturningToOffice);
    expect($order->return_fault)->toBe(ReturnFault::Driver);
    expect($order->return_office_id)->toBe($this->office->id);

    $order = app(FailedDeliveryService::class)->receiveReturn($this->officeStaff, $order, 'A1', 'received');
    $after = (string) DriverAccount::query()->where('driver_id', $this->driver->id)->value('earnings_balance');

    expect($order->status)->toBe(OrderStatus::AtOffice);
    expect(bccomp($after, $before, 2))->toBe(0); // no earnings for driver-fault
    expect(DriverProfile::query()->where('user_id', $this->driver->id)->firstOrFail()->activity_status)
        ->toBe(DriverActivityStatus::Online);
});

it('receiver-fault retrieval credits driver at receipt and collects delivery + storage', function (): void {
    $order = ($this->orderToPostPickup)('+218910000302', 'receiver');
    $before = (string) DriverAccount::query()->where('driver_id', $this->driver->id)->value('earnings_balance');

    $order = app(FailedDeliveryService::class)->markDeliveryFailedByDriver($this->driver, $order, ReturnReason::ReceiverRefused, 'refused');
    $order = app(FailedDeliveryService::class)->receiveReturn($this->officeStaff, $order);
    $after = (string) DriverAccount::query()->where('driver_id', $this->driver->id)->value('earnings_balance');

    $expectedCredit = bcsub((string) $order->delivery_fee, (string) $order->driver_fee_cut_amount, 2);
    expect(bccomp(bcsub($after, $before, 2), $expectedCredit, 2))->toBe(0);

    OfficeInventory::query()->where('order_id', $order->id)->update(['received_at' => now()->subDays(7)]);
    $order = app(FailedDeliveryService::class)->retrieve($this->officeStaff, $order, '12.00', 'retrieve');

    expect($order->status)->toBe(OrderStatus::RetrievedBySeller);
    expect($order->delivery_fee_status)->toBe(DeliveryFeeStatus::Paid);
    expect((string) $order->storage_fee_accrued)->toBe('2.00'); // 7 days - 5 grace * 1.00
});

it('sender-paid failed order retrieves with storage only', function (): void {
    $order = ($this->orderToPostPickup)('+218910000303', 'sender');
    $order = app(FailedDeliveryService::class)->markDeliveryFailedByDriver($this->driver, $order, ReturnReason::ReceiverUnreachable);
    $order = app(FailedDeliveryService::class)->receiveReturn($this->officeStaff, $order);
    OfficeInventory::query()->where('order_id', $order->id)->update(['received_at' => now()->subDays(7)]);

    $order = app(FailedDeliveryService::class)->retrieve($this->officeStaff, $order, '2.00');

    expect($order->status)->toBe(OrderStatus::RetrievedBySeller);
    expect($order->delivery_fee_status)->toBe(DeliveryFeeStatus::Paid);
    expect((string) $order->officeInventory->cash_collected_at_retrieval)->toBe('2.00');
});

it('admin redirect-return writes an audit row and changes office without status change', function (): void {
    $alternate = OfficeLocation::create([
        'region_id' => $this->region->id, 'name' => 'Alt Office', 'address' => 'alt',
        'location' => Point::makeGeodetic(32.90, 13.20), 'is_active' => true,
    ]);
    $order = ($this->orderToPostPickup)('+218910000304', 'receiver');
    $order = app(FailedDeliveryService::class)->markDeliveryFailedByDriver($this->driver, $order, ReturnReason::AddressInvalid);

    $redirected = app(FailedDeliveryService::class)->redirectReturn($this->admin, $order, $alternate, 'redirect');

    expect($redirected->status)->toBe(OrderStatus::ReturningToOffice);
    expect($redirected->return_office_id)->toBe($alternate->id);
    $log = $redirected->statusLogs()->latest('id')->first();
    expect($log?->metadata['event'] ?? null)->toBe('return_office_redirected');
});

it('admin waiver allows a zero-cash retrieval', function (): void {
    $order = ($this->orderToPostPickup)('+218910000305', 'receiver');
    $order = app(FailedDeliveryService::class)->markDeliveryFailedByDriver($this->driver, $order, ReturnReason::ReceiverUnreachable);
    $order = app(FailedDeliveryService::class)->receiveReturn($this->officeStaff, $order);
    OfficeInventory::query()->where('order_id', $order->id)->update(['received_at' => now()->subDays(7)]);

    $order = app(FailedDeliveryService::class)->waiveRetrievalFees($this->admin, $order, '12.00', 'full waiver');
    $order = app(FailedDeliveryService::class)->retrieve($this->officeStaff, $order, '0.00');

    expect($order->status)->toBe(OrderStatus::RetrievedBySeller);
    expect((string) $order->officeInventory->retrieval_fees_waived_amount)->toBe('12.00');
});

it('admin can mark delivery failed from picked_up', function (): void {
    $picked = app(AdminAssignmentService::class)->assign($this->admin, ($this->createOrder)('+218910000306', 'receiver'), $this->driver, force: true);
    DB::transaction(function () use (&$picked): void {
        $picked = app(StateTransitionService::class)->transition(
            order: $picked->refresh(),
            to: OrderStatus::PickedUp,
            actorType: OrderActorType::Driver,
            actorId: $this->driver->id,
            metadata: ['event' => 'test_manual_pickup'],
        );
    });

    $failed = app(FailedDeliveryService::class)->markDeliveryFailedByAdmin($this->admin, $picked, ReturnReason::AddressInvalid);
    expect($failed->status)->toBe(OrderStatus::ReturningToOffice);
    expect($failed->return_fault)->toBe(ReturnFault::Sender);
});

it('abandonment cron flips a stale at-office order to abandoned and snapshots storage', function (): void {
    $order = ($this->orderToPostPickup)('+218910000307', 'receiver');
    $order = app(FailedDeliveryService::class)->markDeliveryFailedByDriver($this->driver, $order, ReturnReason::AddressInvalid);
    $order = app(FailedDeliveryService::class)->receiveReturn($this->officeStaff, $order);
    OfficeInventory::query()->where('order_id', $order->id)->update(['received_at' => now()->subDays(30)]);

    app(AbandonStaleOrdersJob::class)->handle(app(FailedDeliveryService::class));

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Abandoned);
    expect((string) $order->storage_fee_accrued)->toBe('25.00'); // 30 - 5 grace * 1.00
});

it('rejects an excess-cash retrieval', function (): void {
    $order = ($this->orderToPostPickup)('+218910000308', 'receiver');
    $order = app(FailedDeliveryService::class)->markDeliveryFailedByDriver($this->driver, $order, ReturnReason::ReceiverRefused);
    $order = app(FailedDeliveryService::class)->receiveReturn($this->officeStaff, $order);

    expect(fn () => app(FailedDeliveryService::class)->retrieve($this->officeStaff, $order, '99.00'))
        ->toThrow(OrderDomainException::class);
});

it('auto-offlines a stale GPS-lost driver', function (): void {
    $profile = DriverProfile::query()->where('user_id', $this->driver->id)->firstOrFail();
    $profile->forceFill([
        'activity_status' => DriverActivityStatus::Online->value,
        'current_location' => Point::makeGeodetic($this->pickup['lat'], $this->pickup['lng']),
        'last_location_updated_at' => now()->subMinutes(6),
        'last_active_at' => now()->subMinutes(6),
    ])->save();

    $processed = app(AutoOfflineService::class)->process($profile);

    expect($processed)->toBe(1);
    expect($profile->fresh()->activity_status)->toBe(DriverActivityStatus::Offline);
    $presence = DriverPresenceLog::query()->where('driver_id', $this->driver->id)->latest('id')->first();
    expect($presence?->event)->toBe('auto_offline');
    expect($presence?->reason)->toBe('gps_lost');
});
