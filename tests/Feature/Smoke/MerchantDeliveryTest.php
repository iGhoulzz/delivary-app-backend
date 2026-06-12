<?php

declare(strict_types=1);

use App\Enums\ItemSize;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\SellerEarningStatus;
use App\Enums\SellerPayoutStatus;
use App\Enums\VehicleType;
use App\Jobs\ClearSellerEarningsJob;
use App\Models\DriverAccount;
use App\Models\MerchantProfile;
use App\Models\OfficeStaffAssignment;
use App\Models\PlatformSetting;
use App\Models\SellerEarning;
use App\Models\User;
use App\Services\Driver\PresenceService;
use App\Services\Merchant\MerchantOrderCreationService;
use App\Services\Order\ClaimService;
use App\Services\Order\CodeVerificationService;
use App\Services\Order\QuoteService;
use App\Services\Settlement\SellerPayoutService;
use App\Services\Settlement\SettlementService;
use App\ValueObjects\MerchantOrderContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestWorld;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $world = TestWorld::create();
    $this->office = $world['office'];
    $this->pickup = $world['pickup'];
    $this->dropoff = $world['dropoff'];

    PlatformSetting::set('payouts.clearance_hours', 48);
    PlatformSetting::set('payouts.min_amount', '20.00');

    $this->officeStaff = User::factory()->create();
    $this->officeStaff->assignRole('office_staff');
    OfficeStaffAssignment::create([
        'user_id' => $this->officeStaff->id,
        'office_id' => $this->office->id,
        'is_manager' => true,
        'assigned_at' => now(),
    ]);
});

it('runs the full merchant delivery lifecycle: order → deliver → earn → settle → payout', function (): void {
    // 1. An active merchant with a negotiated 5% commission override.
    $merchant = MerchantProfile::factory()->create([
        'business_name' => 'Acme Shop',
        'business_phone' => '+218915550000',
        'commission_rate_override' => '0.0500',
    ]);
    $merchant->user->assignRole('merchant');

    $driver = makeOnlineDriverAt($this->pickup['lat'], $this->pickup['lng'], VehicleType::Car);

    // 2. Merchant creates a merchant_delivery order (goods worth 100, cash at delivery).
    $ctx = MerchantOrderContext::fromProfile($merchant);
    $quote = app(QuoteService::class)->quote(
        OrderType::MerchantDelivery,
        $this->pickup['lat'], $this->pickup['lng'],
        $this->dropoff['lat'], $this->dropoff['lng'],
        ItemSize::Small, '100.00', 'receiver', $ctx,
    );

    $order = app(MerchantOrderCreationService::class)->create($merchant->user, [
        'quote_token' => $quote['quote_token'],
        'item_price' => '100.00',
        'pickup_location' => $this->pickup,
        'pickup_address' => 'Acme depot',
        'receiver_location' => $this->dropoff,
        'receiver_address' => 'Customer address',
        'receiver_phone' => '+218910000333',
        'receiver_name' => 'Customer',
        'item_size' => ItemSize::Small->value,
        'item_description' => 'A box of goods',
    ]);

    expect($order->order_type)->toBe(OrderType::MerchantDelivery)
        ->and($order->merchant_profile_id)->toBe($merchant->id)
        ->and($order->sender_user_id)->toBe($merchant->user_id)
        ->and($order->sender_name)->toBe('Acme Shop')
        ->and((string) $order->commission_amount)->toBe('5.00');

    // 3. Driver delivers end to end.
    $order = app(ClaimService::class)->claim($driver, $order);
    $order = app(CodeVerificationService::class)->confirmPickup($driver, $order, 'code', $order->pickup_code);
    app(PresenceService::class)->updateLocation($driver, $this->dropoff);
    $order = app(CodeVerificationService::class)->arrivedDropoff($driver, $order);
    $order = app(CodeVerificationService::class)->confirmDelivery($driver, $order, $order->delivery_code);

    expect($order->status)->toBe(OrderStatus::Delivered);

    // 4. A seller earning spawned for the MERCHANT user: item_price - commission = 95.
    $earning = SellerEarning::query()->where('order_id', $order->id)->firstOrFail();
    expect($earning->seller_user_id)->toBe($merchant->user_id)
        ->and((string) $earning->amount)->toBe('95.00')
        ->and($earning->status)->toBe(SellerEarningStatus::PendingSettlement);

    // 5. Office settles the driver (matched): the office receives the net cash
    //    (collected cash minus the driver's earned fees, which are netted out).
    $acct = DriverAccount::query()->where('driver_id', $driver->id)->firstOrFail();
    $net = bcsub((string) $acct->cash_to_deposit, (string) $acct->earnings_balance, 2);
    app(SettlementService::class)->process($driver, $this->officeStaff, $this->office, $net, '0.00', null);
    expect($earning->fresh()->status)->toBe(SellerEarningStatus::PendingClearance);

    // 6. After the 48h clearance window, the earning becomes available.
    SellerEarning::query()->where('id', $earning->id)->update(['cleared_at' => now()->subHours(49)]);
    (new ClearSellerEarningsJob)->handle();
    expect($earning->fresh()->status)->toBe(SellerEarningStatus::Available);

    // 7. The merchant is paid out as the seller.
    $payout = app(SellerPayoutService::class)->process(
        $merchant->user,
        $this->officeStaff,
        $this->office,
        collect([$earning->fresh()->public_id]),
        '95.00',
    );

    expect($payout->status)->toBe(SellerPayoutStatus::Paid)
        ->and($earning->fresh()->status)->toBe(SellerEarningStatus::PaidOut);
});

it('spawns no seller earning for a pure-fulfillment merchant order (item_price = 0)', function (): void {
    $merchant = MerchantProfile::factory()->create();
    $merchant->user->assignRole('merchant');
    $driver = makeOnlineDriverAt($this->pickup['lat'], $this->pickup['lng'], VehicleType::Car);

    $ctx = MerchantOrderContext::fromProfile($merchant);
    $quote = app(QuoteService::class)->quote(
        OrderType::MerchantDelivery,
        $this->pickup['lat'], $this->pickup['lng'],
        $this->dropoff['lat'], $this->dropoff['lng'],
        ItemSize::Small, '0.00', 'receiver', $ctx,
    );

    $order = app(MerchantOrderCreationService::class)->create($merchant->user, [
        'quote_token' => $quote['quote_token'],
        'item_price' => '0',
        'pickup_location' => $this->pickup,
        'pickup_address' => 'Acme depot',
        'receiver_location' => $this->dropoff,
        'receiver_address' => 'Customer address',
        'receiver_phone' => '+218910000444',
        'receiver_name' => 'Customer',
        'item_size' => ItemSize::Small->value,
        'item_description' => 'A box of goods',
    ]);

    $order = app(ClaimService::class)->claim($driver, $order);
    $order = app(CodeVerificationService::class)->confirmPickup($driver, $order, 'code', $order->pickup_code);
    app(PresenceService::class)->updateLocation($driver, $this->dropoff);
    $order = app(CodeVerificationService::class)->arrivedDropoff($driver, $order);
    $order = app(CodeVerificationService::class)->confirmDelivery($driver, $order, $order->delivery_code);

    expect($order->status)->toBe(OrderStatus::Delivered)
        ->and(SellerEarning::query()->where('order_id', $order->id)->exists())->toBeFalse();
});
