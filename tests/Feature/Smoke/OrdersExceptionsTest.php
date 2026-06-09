<?php

declare(strict_types=1);

use App\Enums\DriverAccountBucket;
use App\Enums\DriverAccountTransactionReason;
use App\Enums\DriverActivityStatus;
use App\Enums\DriverStrikeIssuer;
use App\Enums\DriverStrikeReason;
use App\Enums\ItemSize;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\VehicleType;
use App\Exceptions\Order\OrderDomainException;
use App\Models\DriverAccount;
use App\Models\DriverAccountTransaction;
use App\Models\DriverProfile;
use App\Models\DriverStrike;
use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Order\AdminAssignmentService;
use App\Services\Order\CancellationService;
use App\Services\Order\CodeVerificationService;
use App\Services\Order\CreationService;
use App\Services\Order\EscalationService;
use App\Services\Order\QuoteService;
use App\Services\Order\RetryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestWorld;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $world = TestWorld::create();
    $this->pickup = $world['pickup'];
    $this->dropoff = $world['dropoff'];

    PlatformSetting::set('cancellation.user_pre_pickup_fee', '3.50');
    PlatformSetting::set('cancellation.driver_accept_then_cancel_fee', '7.00');

    $this->sender = User::factory()->create();
    $this->sender->assignRole('user');
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->driver = makeOnlineDriverAt($this->pickup['lat'], $this->pickup['lng'], VehicleType::Car);

    $this->createOrder = function (string $receiverPhone): Order {
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

it('applies tier-2 and tier-3 surcharges on escalation', function (): void {
    $tier2 = ($this->createOrder)('+218910000201');
    $tier2->forceFill(['awaiting_driver_at' => now()->subMinutes(4), 'status_changed_at' => now()->subMinutes(4)])->save();
    app(EscalationService::class)->process($tier2);
    $tier2->refresh();
    expect($tier2->search_radius_tier)->toBe(2);
    expect((string) $tier2->delivery_fee)->toBe('12.00');

    $tier3 = ($this->createOrder)('+218910000202');
    $tier3->forceFill(['awaiting_driver_at' => now()->subMinutes(7), 'status_changed_at' => now()->subMinutes(7)])->save();
    app(EscalationService::class)->process($tier3);
    $tier3->refresh();
    expect($tier3->search_radius_tier)->toBe(3);
    expect((string) $tier3->delivery_fee)->toBe('15.00');
});

it('times out to no_driver_available', function (): void {
    $timeout = ($this->createOrder)('+218910000203');
    $timeout->forceFill(['awaiting_driver_at' => now()->subMinutes(11), 'status_changed_at' => now()->subMinutes(11)])->save();
    app(EscalationService::class)->process($timeout);
    expect($timeout->refresh()->status)->toBe(OrderStatus::NoDriverAvailable);
});

it('retries from no_driver_available back to awaiting_driver tier 1, then free-cancels', function (): void {
    $order = ($this->createOrder)('+218910000204');
    $order->forceFill(['status' => OrderStatus::NoDriverAvailable->value, 'no_driver_available_at' => now()])->save();

    $retried = app(RetryService::class)->retry($this->sender, $order);
    expect($retried->status)->toBe(OrderStatus::AwaitingDriver);
    expect($retried->search_radius_tier)->toBe(1);

    $retried->forceFill(['status' => OrderStatus::NoDriverAvailable->value, 'no_driver_available_at' => now()])->save();
    $cancelled = app(CancellationService::class)->cancelByUserFromNoDriver($this->sender, $retried, 'changed mind');
    expect($cancelled->status)->toBe(OrderStatus::CancelledByUser);
    expect((string) $cancelled->cancellation_fee)->toBe('0.00');
});

it('admin assigns then unassigns an order', function (): void {
    $order = ($this->createOrder)('+218910000205');
    $assigned = app(AdminAssignmentService::class)->assign($this->admin, $order, $this->driver, force: true);
    expect($assigned->status)->toBe(OrderStatus::DriverEnRoutePickup);
    expect($assigned->driver_id)->toBe($this->driver->id);

    $unassigned = app(AdminAssignmentService::class)->unassign($this->admin, $assigned, 'reassign', resetTier: true);
    expect($unassigned->status)->toBe(OrderStatus::AwaitingDriver);
    expect($unassigned->driver_id)->toBeNull();
});

it('charges no fee for an awaiting-driver cancel, but the configured fee post-assignment', function (): void {
    $free = app(CancellationService::class)->cancelByUser($this->sender, ($this->createOrder)('+218910000206'), 'free');
    expect($free->status)->toBe(OrderStatus::CancelledByUser);
    expect((string) $free->cancellation_fee)->toBe('0.00');

    $assigned = app(AdminAssignmentService::class)->assign($this->admin, ($this->createOrder)('+218910000207'), $this->driver, force: true);
    $feeCancel = app(CancellationService::class)->cancelByUser($this->sender, $assigned, 'fee');
    expect($feeCancel->status)->toBe(OrderStatus::CancelledByUser);
    expect((string) $feeCancel->cancellation_fee)->toBe('3.50');
    expect(DriverProfile::query()->where('user_id', $this->driver->id)->firstOrFail()->activity_status)
        ->toBe(DriverActivityStatus::Online);
});

it('admin pre-pickup cancel frees the assigned driver', function (): void {
    $assigned = app(AdminAssignmentService::class)->assign($this->admin, ($this->createOrder)('+218910000208'), $this->driver, force: true);
    $cancelled = app(CancellationService::class)->cancelByAdmin($this->admin, $assigned, 'admin cancel');
    expect($cancelled->status)->toBe(OrderStatus::CancelledByAdmin);
    expect(DriverProfile::query()->where('user_id', $this->driver->id)->firstOrFail()->activity_status)
        ->toBe(DriverActivityStatus::Online);
});

it('driver-fault unassign creates a strike and debits earnings first', function (): void {
    // Arrange: give the driver earnings so the strike fee debits EarningsBalance
    // (shared-state trap: the smoke relied on a prior delivery's earnings).
    DriverAccount::query()->where('driver_id', $this->driver->id)->update(['earnings_balance' => '20.00']);

    $order = app(AdminAssignmentService::class)->assign($this->admin, ($this->createOrder)('+218910000209'), $this->driver, force: true);

    $unassigned = app(AdminAssignmentService::class)->unassign(
        $this->admin, $order, 'accept then cancel',
        resetTier: true, driverFault: true, notes: 'fault', feeAmountOverride: '7.00',
    );

    expect($unassigned->status)->toBe(OrderStatus::AwaitingDriver);
    expect($unassigned->driver_id)->toBeNull();
    expect(DriverProfile::query()->where('user_id', $this->driver->id)->firstOrFail()->activity_status)
        ->toBe(DriverActivityStatus::Offline);

    $strike = DriverStrike::query()->latest('id')->firstOrFail();
    expect($strike->reason)->toBe(DriverStrikeReason::AcceptThenCancel);
    expect($strike->issued_by)->toBe(DriverStrikeIssuer::System);

    $tx = DriverAccountTransaction::query()
        ->where('driver_id', $this->driver->id)
        ->where('reason', DriverAccountTransactionReason::StrikeFee->value)
        ->latest('id')->firstOrFail();
    expect($tx->bucket)->toBe(DriverAccountBucket::EarningsBalance);
    expect((string) $tx->amount)->toBe('-7.00');
});

it('rejects an after-pickup sender cancel', function (): void {
    $order = app(AdminAssignmentService::class)->assign($this->admin, ($this->createOrder)('+218910000210'), $this->driver, force: true);
    $order = app(CodeVerificationService::class)->confirmPickup($this->driver, $order, 'code', $order->pickup_code);

    expect(fn () => app(CancellationService::class)->cancelByUser($this->sender, $order, 'too late'))
        ->toThrow(OrderDomainException::class);
});
