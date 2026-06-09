<?php

declare(strict_types=1);

use App\Enums\DeliveryFeeStatus;
use App\Enums\DriverActivityStatus;
use App\Enums\DriverStatus;
use App\Enums\ItemSize;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\SellerEarningStatus;
use App\Enums\SellerPayoutStatus;
use App\Enums\SettlementStatus;
use App\Enums\VehicleType;
use App\Exceptions\Settlement\EmptySettlementException;
use App\Exceptions\Settlement\PayoutValidationException;
use App\Exceptions\Settlement\SettlementExcessException;
use App\Exceptions\Settlement\SettlementNotReversibleException;
use App\Jobs\ClearSellerEarningsJob;
use App\Models\DriverAccount;
use App\Models\DriverProfile;
use App\Models\OfficeStaffAssignment;
use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\SellerEarning;
use App\Models\SettlementOrder;
use App\Models\User;
use App\Services\Settlement\SellerPayoutService;
use App\Services\Settlement\SettlementReversalService;
use App\Services\Settlement\SettlementService;
use Carbon\Carbon;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestWorld;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $world = TestWorld::create();
    $this->office = $world['office'];
    $this->pickup = $world['pickup'];
    $this->dropoff = $world['dropoff'];

    PlatformSetting::set('payouts.clearance_hours', 48);
    PlatformSetting::set('payouts.min_amount', '20.00');

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->officeStaff = User::factory()->create();
    $this->officeStaff->assignRole('office_staff');
    OfficeStaffAssignment::create([
        'user_id' => $this->officeStaff->id,
        'office_id' => $this->office->id,
        'is_manager' => true,
        'assigned_at' => now(),
    ]);

    $this->settlements = app(SettlementService::class);
    $this->payouts = app(SellerPayoutService::class);
    $this->reversals = app(SettlementReversalService::class);

    $this->freshDriver = function (string $cash, string $earnings, string $debt): User {
        $d = User::factory()->create(['account_status' => 'active']);
        $d->assignRole('driver');
        DriverProfile::create([
            'user_id' => $d->id, 'office_id' => null,
            'status' => DriverStatus::Active->value, 'vehicle_type' => VehicleType::Car->value,
            'vehicle_plate' => 'S-'.Str::upper(Str::random(4)),
            'activity_status' => DriverActivityStatus::Offline->value,
        ]);
        DriverAccount::create([
            'driver_id' => $d->id, 'cash_to_deposit' => $cash,
            'earnings_balance' => $earnings, 'debt_balance' => $debt, 'max_cash_liability' => '1000.00',
        ]);

        return $d;
    };

    $this->freshEarning = function (User $driver, User $seller, string $amount, string $status = 'pending_settlement', ?Carbon $clearedAt = null, ?Carbon $availableAt = null): SellerEarning {
        $order = Order::create([
            'tracking_token' => (string) Str::ulid(),
            'order_type' => OrderType::P2pSale->value,
            'status' => OrderStatus::Delivered->value,
            'sender_user_id' => $seller->id,
            'sender_phone' => $seller->phone_number,
            'sender_name' => 'S',
            'pickup_address' => 'S pickup',
            'pickup_location' => Point::makeGeodetic($this->pickup['lat'], $this->pickup['lng']),
            'pickup_code' => '111111',
            'receiver_type' => 'guest',
            'receiver_phone' => '+2189'.fake()->unique()->numerify('########'),
            'receiver_name' => 'R',
            'receiver_address' => 'R drop',
            'receiver_location' => Point::makeGeodetic($this->dropoff['lat'], $this->dropoff['lng']),
            'delivery_code' => '222222',
            'driver_id' => $driver->id,
            'item_description' => 'S item',
            'item_size' => ItemSize::Small->value,
            'item_price' => bcadd($amount, '5.00', 2),
            'commission_rate' => '0.0500',
            'commission_amount' => '5.00',
            'delivery_fee_base' => '10.00',
            'delivery_fee' => '10.00',
            'driver_fee_cut_amount' => '1.00',
            'delivery_fee_payer' => 'sender',
            'delivery_fee_payment_method' => 'cash',
            'delivery_fee_status' => DeliveryFeeStatus::Paid->value,
            'delivered_at' => now(),
            'status_changed_at' => now(),
        ]);

        return SellerEarning::create([
            'order_id' => $order->id, 'seller_user_id' => $seller->id,
            'amount' => $amount, 'status' => $status,
            'cleared_at' => $clearedAt, 'available_at' => $availableAt,
        ]);
    };

    $this->seller = function (): User {
        $u = User::factory()->create();
        $u->assignRole('user');

        return $u;
    };
});

it('settles a happy match clearing all three buckets', function (): void {
    $d = ($this->freshDriver)('100.00', '30.00', '0.00');
    $settlement = $this->settlements->process($d, $this->officeStaff, $this->office, '70.00', '0.00', null);
    $acct = DriverAccount::query()->where('driver_id', $d->id)->first();

    expect($settlement->status)->toBe(SettlementStatus::Completed);
    expect((string) $acct->cash_to_deposit)->toBe('0.00');
    expect((string) $acct->earnings_balance)->toBe('0.00');
    expect((string) $acct->debt_balance)->toBe('0.00');
});

it('rejects a settlement with empty buckets', function (): void {
    $d = ($this->freshDriver)('0.00', '0.00', '0.00');
    expect(fn () => $this->settlements->process($d, $this->officeStaff, $this->office, '0.00', '0.00', null))
        ->toThrow(EmptySettlementException::class);
});

it('rejects an excess settlement', function (): void {
    $d = ($this->freshDriver)('50.00', '0.00', '0.00');
    expect(fn () => $this->settlements->process($d, $this->officeStaff, $this->office, '80.00', '0.00', null))
        ->toThrow(SettlementExcessException::class);
});

it('pushes an acknowledged shortage to debt_balance', function (): void {
    $d = ($this->freshDriver)('100.00', '0.00', '0.00');
    $settlement = $this->settlements->process($d, $this->officeStaff, $this->office, '70.00', '0.00', 'short');
    $acct = DriverAccount::query()->where('driver_id', $d->id)->first();

    expect((string) $settlement->shortage_amount)->toBe('30.00');
    expect((string) $acct->debt_balance)->toBe('30.00');
    expect((string) $acct->cash_to_deposit)->toBe('0.00');
});

it('handles a zero-net settlement with no cash movement', function (): void {
    $d = ($this->freshDriver)('50.00', '50.00', '0.00');
    $settlement = $this->settlements->process($d, $this->officeStaff, $this->office, '0.00', '0.00', null);
    expect($settlement->cashMovement())->toBe('0.00');
});

it('flips pending_settlement earnings to pending_clearance on settlement', function (): void {
    $d = ($this->freshDriver)('200.00', '0.00', '0.00');
    $seller = ($this->seller)();
    $a = ($this->freshEarning)($d, $seller, '95.00');
    $b = ($this->freshEarning)($d, $seller, '47.50');

    $this->settlements->process($d, $this->officeStaff, $this->office, '200.00', '0.00', null);

    expect($a->fresh()->status)->toBe(SellerEarningStatus::PendingClearance);
    expect($b->fresh()->status)->toBe(SellerEarningStatus::PendingClearance);
    expect($a->fresh()->cleared_at)->not->toBeNull();
});

it('clearance cron promotes eligible earnings to available', function (): void {
    $d = ($this->freshDriver)('0.00', '0.00', '0.00');
    $seller = ($this->seller)();
    $earning = ($this->freshEarning)($d, $seller, '60.00', 'pending_clearance', now()->subHours(49));

    (new ClearSellerEarningsJob)->handle();

    expect($earning->fresh()->status)->toBe(SellerEarningStatus::Available);
    expect($earning->fresh()->available_at)->not->toBeNull();
});

it('clearance cron skips earnings younger than the clearance window', function (): void {
    $d = ($this->freshDriver)('0.00', '0.00', '0.00');
    $seller = ($this->seller)();
    $earning = ($this->freshEarning)($d, $seller, '60.00', 'pending_clearance', now()->subHours(10));

    (new ClearSellerEarningsJob)->handle();

    expect($earning->fresh()->status)->toBe(SellerEarningStatus::PendingClearance);
});

it('pays out three available earnings', function (): void {
    $d = ($this->freshDriver)('0.00', '0.00', '0.00');
    $seller = ($this->seller)();
    $a = ($this->freshEarning)($d, $seller, '30.00', 'available', now()->subHours(72), now()->subHours(24));
    $b = ($this->freshEarning)($d, $seller, '47.00', 'available', now()->subHours(72), now()->subHours(24));
    $c = ($this->freshEarning)($d, $seller, '25.00', 'available', now()->subHours(72), now()->subHours(24));

    $payout = $this->payouts->process($seller, $this->officeStaff, $this->office, collect([$a->public_id, $b->public_id, $c->public_id]), '102.00');

    expect((string) $payout->amount)->toBe('102.00');
    expect($payout->status)->toBe(SellerPayoutStatus::Paid);
    expect($payout->orders()->count())->toBe(3);
    foreach ([$a, $b, $c] as $e) {
        expect($e->fresh()->status)->toBe(SellerEarningStatus::PaidOut);
    }
});

it('leaves unselected earnings available on a partial payout', function (): void {
    $d = ($this->freshDriver)('0.00', '0.00', '0.00');
    $seller = ($this->seller)();
    $f1 = ($this->freshEarning)($d, $seller, '30.00', 'available', now()->subHours(72), now()->subHours(24));
    $f2 = ($this->freshEarning)($d, $seller, '47.00', 'available', now()->subHours(72), now()->subHours(24));

    $this->payouts->process($seller, $this->officeStaff, $this->office, collect([$f1->public_id]), '30.00');

    expect($f2->fresh()->status)->toBe(SellerEarningStatus::Available);
});

it('rejects a payout whose total does not match the selection', function (): void {
    $d = ($this->freshDriver)('0.00', '0.00', '0.00');
    $seller = ($this->seller)();
    $g1 = ($this->freshEarning)($d, $seller, '30.00', 'available', now()->subHours(72), now()->subHours(24));
    $g2 = ($this->freshEarning)($d, $seller, '20.00', 'available', now()->subHours(72), now()->subHours(24));

    expect(fn () => $this->payouts->process($seller, $this->officeStaff, $this->office, collect([$g1->public_id, $g2->public_id]), '100.00'))
        ->toThrow(PayoutValidationException::class);
});

it('rejects a payout below the minimum', function (): void {
    $d = ($this->freshDriver)('0.00', '0.00', '0.00');
    $seller = ($this->seller)();
    $h1 = ($this->freshEarning)($d, $seller, '5.00', 'available', now()->subHours(72), now()->subHours(24));

    expect(fn () => $this->payouts->process($seller, $this->officeStaff, $this->office, collect([$h1->public_id]), '5.00'))
        ->toThrow(PayoutValidationException::class);
});

it('reverses a settlement while earnings are still pending_clearance', function (): void {
    $d = ($this->freshDriver)('100.00', '0.00', '0.00');
    $seller = ($this->seller)();
    $earning = ($this->freshEarning)($d, $seller, '95.00');
    $orig = $this->settlements->process($d, $this->officeStaff, $this->office, '100.00', '0.00', null);

    $correcting = $this->reversals->reverse($orig, $this->admin, 'miscount');
    $acct = DriverAccount::query()->where('driver_id', $d->id)->first();

    expect($orig->fresh()->status)->toBe(SettlementStatus::Cancelled);
    expect($correcting->status)->toBe(SettlementStatus::Completed);
    expect($earning->fresh()->status)->toBe(SellerEarningStatus::PendingSettlement);
    expect((string) $acct->cash_to_deposit)->toBe('100.00');
});

it('blocks a reversal once an earning has advanced past pending_clearance', function (): void {
    $d = ($this->freshDriver)('100.00', '0.00', '0.00');
    $seller = ($this->seller)();
    $earning = ($this->freshEarning)($d, $seller, '95.00');
    $orig = $this->settlements->process($d, $this->officeStaff, $this->office, '100.00', '0.00', null);
    SellerEarning::query()->where('id', $earning->id)->update(['status' => SellerEarningStatus::Available->value, 'available_at' => now()]);

    expect(fn () => $this->reversals->reverse($orig, $this->admin, 'too late'))
        ->toThrow(SettlementNotReversibleException::class);
});

it('restores debt correctly when a settlement cleared old debt and recorded a shortage', function (): void {
    // pre-existing debt 20 + cash 30, driver brings 0 → shortage 50.
    $d = ($this->freshDriver)('30.00', '0.00', '20.00');
    $seller = ($this->seller)();
    ($this->freshEarning)($d, $seller, '40.00');
    $orig = $this->settlements->process($d, $this->officeStaff, $this->office, '0.00', '0.00', null);

    expect((string) $orig->debt_balance_cleared)->toBe('20.00');
    expect((string) $orig->shortage_amount)->toBe('50.00');

    $this->reversals->reverse($orig, $this->admin, 'regression');
    $acct = DriverAccount::query()->where('driver_id', $d->id)->first();

    expect((string) $acct->cash_to_deposit)->toBe('30.00');
    expect((string) $acct->earnings_balance)->toBe('0.00');
    expect((string) $acct->debt_balance)->toBe('20.00');
    expect(SettlementOrder::query()->where('settlement_id', $orig->id)->count())->toBe(1);
});
