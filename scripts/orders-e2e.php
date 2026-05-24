<?php

declare(strict_types=1);

// Force null broadcaster so this smoke script doesn't try to reach a real
// Reverb server. Phase 2 (OrderStatusChanged ShouldBroadcast) makes the
// lifecycle dispatch broadcasts; with BROADCAST_CONNECTION=reverb in .env
// and no local Reverb running, dispatches would fail. Setting null here
// keeps the smoke self-contained regardless of dev env state.
config(['broadcasting.default' => 'null']);

use App\Enums\AccountStatus;
use App\Enums\DeliveryFeeStatus;
use App\Enums\DriverAccountBucket;
use App\Enums\DriverAccountTransactionReason;
use App\Enums\DriverActivityStatus;
use App\Enums\DriverStatus;
use App\Enums\DriverStrikeIssuer;
use App\Enums\DriverStrikeReason;
use App\Enums\ItemSize;
use App\Enums\OrderActorType;
use App\Enums\OrderErrorCode;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ReturnFault;
use App\Enums\ReturnReason;
use App\Enums\SellerEarningStatus;
use App\Enums\SellerPayoutStatus;
use App\Enums\SettlementStatus;
use App\Enums\VehicleType;
use App\Exceptions\Order\OrderDomainException;
use App\Exceptions\Settlement\EmptySettlementException;
use App\Exceptions\Settlement\PayoutValidationException;
use App\Exceptions\Settlement\SettlementExcessException;
use App\Exceptions\Settlement\SettlementNotReversibleException;
use App\Jobs\AbandonStaleOrdersJob;
use App\Jobs\ClearSellerEarningsJob;
use App\Models\DriverAccount;
use App\Models\DriverAccountTransaction;
use App\Models\DriverPresenceLog;
use App\Models\DriverProfile;
use App\Models\DriverStrike;
use App\Models\OfficeInventory;
use App\Models\OfficeLocation;
use App\Models\OfficeStaffAssignment;
use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\Region;
use App\Models\SellerEarning;
use App\Models\Settlement;
use App\Models\SettlementOrder;
use App\Models\User;
use App\Services\Driver\AutoOfflineService;
use App\Services\Driver\PresenceService;
use App\Services\Order\AdminAssignmentService;
use App\Services\Order\BroadcastService;
use App\Services\Order\CancellationService;
use App\Services\Order\ClaimService;
use App\Services\Order\CodeVerificationService;
use App\Services\Order\CreationService;
use App\Services\Order\EscalationService;
use App\Services\Order\FailedDeliveryService;
use App\Services\Order\QuoteService;
use App\Services\Order\RetryService;
use App\Services\Order\StateTransitionService;
use App\Services\Settlement\SellerPayoutService;
use App\Services\Settlement\SettlementReversalService;
use App\Services\Settlement\SettlementService;
use Carbon\Carbon;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }

    echo "PASS {$message}\n";
};

$uniquePhone = static function (int $offset): string {
    $suffix = str_pad((string) random_int(100000 + $offset, 899999 + $offset), 6, '0', STR_PAD_LEFT);

    return '+21891'.$suffix;
};

$makeUser = static function (string $firstName, string $phone, ?string $role = null): User {
    $user = User::create([
        'first_name' => $firstName,
        'last_name' => 'Smoke',
        'phone_number' => $phone,
        'password' => Hash::make('password'),
        'account_status' => AccountStatus::Active->value,
        'phone_verified_at' => now(),
    ]);

    if ($role !== null) {
        $user->assignRole($role);
    }

    return $user;
};

DB::beginTransaction();

try {
    Role::findOrCreate('user', 'web');
    Role::findOrCreate('driver', 'web');
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('office_staff', 'web');

    PlatformSetting::set('codes.enforce_pickup', true);
    PlatformSetting::set('codes.enforce_delivery', true);
    PlatformSetting::set('cancellation.user_pre_pickup_fee', '3.50');
    PlatformSetting::set('cancellation.driver_accept_then_cancel_fee', '7.00');
    PlatformSetting::set('storage.grace_days', 5);
    PlatformSetting::set('storage.daily_fee', '1.00');
    PlatformSetting::set('storage.abandonment_days', 30);

    $region = Region::query()->firstOrFail();
    $region->forceFill(['base_fee' => '10.00', 'is_active' => true])->save();
    DB::table('service_areas')->where('id', $region->service_area_id)->update(['is_active' => true]);

    $centroid = DB::selectOne(
        'SELECT ST_X(ST_Centroid(boundary::geometry)) AS lng, ST_Y(ST_Centroid(boundary::geometry)) AS lat FROM regions WHERE id = ?',
        [$region->id],
    );
    $pickup = ['lat' => (float) $centroid->lat, 'lng' => (float) $centroid->lng];
    $dropoff = ['lat' => (float) $centroid->lat + 0.001, 'lng' => (float) $centroid->lng + 0.001];
    $office = OfficeLocation::query()->first();
    if ($office === null) {
        $office = OfficeLocation::create([
            'region_id' => $region->id,
            'name' => 'E2E Return Office',
            'address' => 'E2E office',
            'location' => Point::makeGeodetic($pickup['lat'], $pickup['lng']),
            'is_active' => true,
        ]);
    }
    $office->forceFill(['is_active' => true])->save();
    $region->forceFill(['office_id' => $office->id])->save();

    $alternateOffice = OfficeLocation::create([
        'region_id' => $region->id,
        'name' => 'E2E Alternate Return Office',
        'address' => 'E2E alternate office',
        'location' => Point::makeGeodetic($pickup['lat'] + 0.01, $pickup['lng'] + 0.01),
        'is_active' => true,
    ]);

    $sender = $makeUser('Sender', $uniquePhone(1), 'user');
    $driver = $makeUser('Driver', $uniquePhone(2), 'driver');
    $admin = $makeUser('Admin', $uniquePhone(3), 'admin');
    $officeStaff = $makeUser('Office', $uniquePhone(14), 'office_staff');

    OfficeStaffAssignment::create([
        'user_id' => $officeStaff->id,
        'office_id' => $office->id,
        'is_manager' => true,
        'assigned_at' => now(),
    ]);

    DriverProfile::create([
        'user_id' => $driver->id,
        'office_id' => null,
        'status' => DriverStatus::Active->value,
        'vehicle_type' => VehicleType::Car->value,
        'vehicle_plate' => 'E2E-'.Str::upper(Str::random(4)),
        'activity_status' => DriverActivityStatus::Offline->value,
    ]);

    DriverAccount::create([
        'driver_id' => $driver->id,
        'cash_to_deposit' => '0.00',
        'earnings_balance' => '0.00',
        'debt_balance' => '0.00',
        'max_cash_liability' => '1000.00',
    ]);

    $createOrder = static function (string $receiverPhone, string $payer = 'sender') use ($sender, $pickup, $dropoff) {
        $quote = app(QuoteService::class)->quote(
            OrderType::StandardDelivery,
            $pickup['lat'],
            $pickup['lng'],
            $dropoff['lat'],
            $dropoff['lng'],
            ItemSize::Small,
            '0.00',
            $payer,
        );

        return app(CreationService::class)->create($sender, [
            'quote_token' => $quote['quote_token'],
            'order_type' => OrderType::StandardDelivery->value,
            'pickup_location' => $pickup,
            'pickup_address' => 'E2E pickup',
            'receiver_location' => $dropoff,
            'receiver_address' => 'E2E dropoff',
            'receiver_phone' => $receiverPhone,
            'receiver_name' => 'E2E Receiver',
            'item_size' => ItemSize::Small->value,
            'item_description' => 'E2E smoke parcel',
            'delivery_fee_payer' => $payer,
        ]);
    };

    $orderToPostPickup = static function (string $receiverPhone, string $payer = 'sender') use ($createOrder, $admin, $driver): Order {
        $order = app(AdminAssignmentService::class)->assign($admin, $createOrder($receiverPhone, $payer), $driver, force: true);

        return app(CodeVerificationService::class)->confirmPickup($driver, $order, 'code', $order->pickup_code);
    };

    echo "Scenario 1: happy path delivery\n";
    $order = $createOrder($uniquePhone(4));
    $assert($order->status === OrderStatus::AwaitingDriver, 'order created in awaiting_driver');
    $assert($order->statusLogs()->count() === 1, 'order creation writes one status log');

    app(PresenceService::class)->goOnline($driver, $pickup);
    $broadcast = app(BroadcastService::class)->candidatesFor($driver);
    $assert($broadcast->where('id', $order->id)->count() === 1, 'broadcast contains awaiting order');

    $order = app(ClaimService::class)->claim($driver, $order);
    $assert($order->status === OrderStatus::DriverEnRoutePickup, 'claim moves order to en_route_pickup');

    $pickupCode = $order->pickup_code;
    $deliveryCode = $order->delivery_code;
    $order = app(CodeVerificationService::class)->confirmPickup($driver, $order, 'code', $pickupCode);
    $assert($order->status === OrderStatus::DriverEnRouteDropoff, 'pickup auto-chains to en_route_dropoff');

    app(PresenceService::class)->updateLocation($driver, $dropoff);
    $order = app(CodeVerificationService::class)->arrivedDropoff($driver, $order);
    $assert($order->status === OrderStatus::DeliveryInProgress, 'arrived-dropoff moves to delivery_in_progress');

    $order = app(CodeVerificationService::class)->confirmDelivery($driver, $order, $deliveryCode);
    $assert($order->status === OrderStatus::Delivered, 'delivery code marks order delivered');

    $account = DriverAccount::where('driver_id', $driver->id)->firstOrFail();
    $assert(bccomp((string) $account->cash_to_deposit, '10.00', 2) === 0, 'sender-paid fee added to cash_to_deposit');
    $assert(bccomp((string) $account->earnings_balance, '9.80', 2) === 0, 'driver earnings credited after 2 percent platform cut');
    $assert(DriverProfile::where('user_id', $driver->id)->firstOrFail()->activity_status === DriverActivityStatus::Online, 'driver returns online after delivery');

    echo "Scenario 2: escalation tiers and no-driver timeout\n";
    $tier2 = $createOrder($uniquePhone(5));
    $tier2->forceFill(['awaiting_driver_at' => now()->subMinutes(4), 'status_changed_at' => now()->subMinutes(4)])->save();
    app(EscalationService::class)->process($tier2);
    $tier2->refresh();
    $assert($tier2->search_radius_tier === 2 && bccomp((string) $tier2->delivery_fee, '12.00', 2) === 0, 'tier 2 applies 20 percent surcharge');

    $tier3 = $createOrder($uniquePhone(6));
    $tier3->forceFill(['awaiting_driver_at' => now()->subMinutes(7), 'status_changed_at' => now()->subMinutes(7)])->save();
    app(EscalationService::class)->process($tier3);
    $tier3->refresh();
    $assert($tier3->search_radius_tier === 3 && bccomp((string) $tier3->delivery_fee, '15.00', 2) === 0, 'tier 3 applies 50 percent surcharge');

    $timeout = $createOrder($uniquePhone(7));
    $timeout->forceFill(['awaiting_driver_at' => now()->subMinutes(11), 'status_changed_at' => now()->subMinutes(11)])->save();
    app(EscalationService::class)->process($timeout);
    $timeout->refresh();
    $assert($timeout->status === OrderStatus::NoDriverAvailable, 'timeout moves order to no_driver_available');

    echo "Scenario 3: retry and free cancel from no_driver_available\n";
    $retried = app(RetryService::class)->retry($sender, $timeout);
    $assert($retried->status === OrderStatus::AwaitingDriver && $retried->search_radius_tier === 1, 'retry resets order to awaiting_driver tier 1');
    $retried->forceFill(['status' => OrderStatus::NoDriverAvailable->value, 'no_driver_available_at' => now()])->save();
    $cancelled = app(CancellationService::class)->cancelByUserFromNoDriver($sender, $retried, 'E2E free cancel');
    $assert($cancelled->status === OrderStatus::CancelledByUser, 'free cancel from no_driver_available succeeds');
    $assert(bccomp((string) $cancelled->cancellation_fee, '0.00', 2) === 0, 'no-driver cancellation stores zero fee');

    echo "Scenario 4: admin assign and unassign\n";
    $manual = $createOrder($uniquePhone(8));
    $assigned = app(AdminAssignmentService::class)->assign($admin, $manual, $driver, force: true);
    $assert($assigned->status === OrderStatus::DriverEnRoutePickup && $assigned->driver_id === $driver->id, 'admin assign moves order to en_route_pickup');
    $unassigned = app(AdminAssignmentService::class)->unassign($admin, $assigned, 'E2E unassign', resetTier: true);
    $assert($unassigned->status === OrderStatus::AwaitingDriver && $unassigned->driver_id === null, 'admin unassign returns order to awaiting_driver');

    echo "Scenario 5: pre-pickup cancellation fees\n";
    $awaitingCancel = app(CancellationService::class)->cancelByUser($sender, $createOrder($uniquePhone(9)), 'E2E awaiting free cancel');
    $assert($awaitingCancel->status === OrderStatus::CancelledByUser, 'sender can cancel awaiting_driver order');
    $assert(bccomp((string) $awaitingCancel->cancellation_fee, '0.00', 2) === 0, 'awaiting_driver cancellation is free');

    $assignedCancel = app(AdminAssignmentService::class)->assign($admin, $createOrder($uniquePhone(10)), $driver, force: true);
    $assignedCancel = app(CancellationService::class)->cancelByUser($sender, $assignedCancel, 'E2E assigned fee cancel');
    $assert($assignedCancel->status === OrderStatus::CancelledByUser, 'sender can cancel driver_en_route_pickup order');
    $assert(bccomp((string) $assignedCancel->cancellation_fee, '3.50', 2) === 0, 'assigned pre-pickup cancellation stores configured fee');
    $assert(DriverProfile::where('user_id', $driver->id)->firstOrFail()->activity_status === DriverActivityStatus::Online, 'sender cancel frees assigned driver online');

    echo "Scenario 6: admin pre-pickup cancellation frees driver\n";
    $adminCancel = app(AdminAssignmentService::class)->assign($admin, $createOrder($uniquePhone(11)), $driver, force: true);
    $adminCancel = app(CancellationService::class)->cancelByAdmin($admin, $adminCancel, 'E2E admin cancel');
    $assert($adminCancel->status === OrderStatus::CancelledByAdmin, 'admin can cancel assigned pre-pickup order');
    $assert(DriverProfile::where('user_id', $driver->id)->firstOrFail()->activity_status === DriverActivityStatus::Online, 'admin cancel frees assigned driver online');

    echo "Scenario 7: driver-fault unassign creates strike and ledger entry\n";
    $strikeOrder = app(AdminAssignmentService::class)->assign($admin, $createOrder($uniquePhone(12)), $driver, force: true);
    $strikeCount = DriverStrike::count();
    $transactionCount = DriverAccountTransaction::query()
        ->where('driver_id', $driver->id)
        ->where('reason', DriverAccountTransactionReason::StrikeFee->value)
        ->count();
    $faultUnassigned = app(AdminAssignmentService::class)->unassign(
        $admin,
        $strikeOrder,
        'E2E accept then cancel',
        resetTier: true,
        driverFault: true,
        notes: 'E2E driver fault unassign',
        feeAmountOverride: '7.00',
    );
    $strike = DriverStrike::query()->latest('id')->firstOrFail();
    $strikeTransaction = DriverAccountTransaction::query()
        ->where('driver_id', $driver->id)
        ->where('reason', DriverAccountTransactionReason::StrikeFee->value)
        ->latest('id')
        ->firstOrFail();
    $assert($faultUnassigned->status === OrderStatus::AwaitingDriver && $faultUnassigned->driver_id === null, 'driver-fault unassign returns order to awaiting_driver');
    $assert(DriverProfile::where('user_id', $driver->id)->firstOrFail()->activity_status === DriverActivityStatus::Offline, 'driver-fault unassign sets driver offline');
    $assert(DriverStrike::count() === $strikeCount + 1, 'driver-fault unassign creates one strike');
    $assert($strike->reason === DriverStrikeReason::AcceptThenCancel && $strike->issued_by === DriverStrikeIssuer::System, 'strike records accept_then_cancel as system-issued');
    $assert(DriverAccountTransaction::query()->where('driver_id', $driver->id)->where('reason', DriverAccountTransactionReason::StrikeFee->value)->count() === $transactionCount + 1, 'strike fee creates a driver account transaction');
    $assert($strikeTransaction->bucket === DriverAccountBucket::EarningsBalance && bccomp((string) $strikeTransaction->amount, '-7.00', 2) === 0, 'strike fee debits existing earnings first');

    echo "Scenario 8: after-pickup sender cancel is rejected\n";
    $afterPickup = app(AdminAssignmentService::class)->assign($admin, $createOrder($uniquePhone(13)), $driver, force: true);
    $afterPickup = app(CodeVerificationService::class)->confirmPickup($driver, $afterPickup, 'code', $afterPickup->pickup_code);

    try {
        app(CancellationService::class)->cancelByUser($sender, $afterPickup, 'E2E after pickup cancel');
        throw new RuntimeException('after-pickup cancellation unexpectedly succeeded');
    } catch (OrderDomainException $exception) {
        $assert($exception->errorCode === OrderErrorCode::OrderNotCancellableFromState, 'after-pickup sender cancel is rejected with expected error code');
    }
    app(PresenceService::class)->updateLocation($driver, $dropoff);
    $afterPickup = app(CodeVerificationService::class)->arrivedDropoff($driver, $afterPickup);
    app(CodeVerificationService::class)->confirmDelivery($driver, $afterPickup, $afterPickup->delivery_code);

    echo "Scenario 9: driver-fault failure returns to office without driver earnings\n";
    $driverFaultOrder = $orderToPostPickup($uniquePhone(15), 'receiver');
    $beforeEarnings = (string) DriverAccount::where('driver_id', $driver->id)->firstOrFail()->earnings_balance;
    $driverFaultOrder = app(FailedDeliveryService::class)->markDeliveryFailedByDriver($driver, $driverFaultOrder, ReturnReason::DriverFault, 'E2E driver fault');
    $assert($driverFaultOrder->status === OrderStatus::ReturningToOffice, 'failed delivery auto-chains to returning_to_office');
    $assert($driverFaultOrder->return_fault === ReturnFault::Driver && $driverFaultOrder->return_office_id === $office->id, 'driver fault and return office are snapshotted');
    $driverFaultOrder = app(FailedDeliveryService::class)->receiveReturn($officeStaff, $driverFaultOrder, 'A1', 'E2E receive driver fault');
    $afterEarnings = (string) DriverAccount::where('driver_id', $driver->id)->firstOrFail()->earnings_balance;
    $assert($driverFaultOrder->status === OrderStatus::AtOffice, 'receive-return transitions to at_office');
    $assert(bccomp($afterEarnings, $beforeEarnings, 2) === 0, 'driver-fault return does not credit driver earnings');
    $assert(DriverProfile::where('user_id', $driver->id)->firstOrFail()->activity_status === DriverActivityStatus::Online, 'receive-return frees driver online');

    echo "Scenario 10: receiver-fault retrieval pays delivery plus storage\n";
    $receiverFaultOrder = $orderToPostPickup($uniquePhone(16), 'receiver');
    $beforeEarnings = (string) DriverAccount::where('driver_id', $driver->id)->firstOrFail()->earnings_balance;
    $receiverFaultOrder = app(FailedDeliveryService::class)->markDeliveryFailedByDriver($driver, $receiverFaultOrder, ReturnReason::ReceiverRefused, 'E2E receiver refused');
    $receiverFaultOrder = app(FailedDeliveryService::class)->receiveReturn($officeStaff, $receiverFaultOrder);
    $afterEarnings = (string) DriverAccount::where('driver_id', $driver->id)->firstOrFail()->earnings_balance;
    $expectedCredit = bcsub((string) $receiverFaultOrder->delivery_fee, (string) $receiverFaultOrder->driver_fee_cut_amount, 2);
    $assert(bccomp(bcsub($afterEarnings, $beforeEarnings, 2), $expectedCredit, 2) === 0, 'receiver-fault return credits driver at office receipt');
    OfficeInventory::where('order_id', $receiverFaultOrder->id)->update(['received_at' => now()->subDays(7)]);
    $receiverFaultOrder = app(FailedDeliveryService::class)->retrieve($officeStaff, $receiverFaultOrder, '12.00', 'E2E retrieve receiver fault');
    $assert($receiverFaultOrder->status === OrderStatus::RetrievedBySeller, 'receiver-fault retrieval transitions to retrieved_by_seller');
    $assert($receiverFaultOrder->delivery_fee_status === DeliveryFeeStatus::Paid, 'receiver-fault retrieval marks delivery fee paid');
    $assert(bccomp((string) $receiverFaultOrder->storage_fee_accrued, '2.00', 2) === 0, 'receiver-fault retrieval snapshots storage fee');

    echo "Scenario 11: sender-paid failed order retrieves with storage only\n";
    $senderPaidReturn = $orderToPostPickup($uniquePhone(17), 'sender');
    $senderPaidReturn = app(FailedDeliveryService::class)->markDeliveryFailedByDriver($driver, $senderPaidReturn, ReturnReason::ReceiverUnreachable);
    $senderPaidReturn = app(FailedDeliveryService::class)->receiveReturn($officeStaff, $senderPaidReturn);
    OfficeInventory::where('order_id', $senderPaidReturn->id)->update(['received_at' => now()->subDays(7)]);
    $senderPaidReturn = app(FailedDeliveryService::class)->retrieve($officeStaff, $senderPaidReturn, '2.00');
    $assert($senderPaidReturn->status === OrderStatus::RetrievedBySeller, 'sender-paid failed order can be retrieved');
    $assert($senderPaidReturn->delivery_fee_status === DeliveryFeeStatus::Paid, 'sender-paid delivery fee stays paid');
    $assert(bccomp((string) $senderPaidReturn->officeInventory->cash_collected_at_retrieval, '2.00', 2) === 0, 'sender-paid retrieval collects storage only');

    echo "Scenario 12: admin redirect-return writes audit row\n";
    $redirectOrder = $orderToPostPickup($uniquePhone(18), 'receiver');
    $redirectOrder = app(FailedDeliveryService::class)->markDeliveryFailedByDriver($driver, $redirectOrder, ReturnReason::AddressInvalid);
    $redirected = app(FailedDeliveryService::class)->redirectReturn($admin, $redirectOrder, $alternateOffice, 'E2E redirect');
    $redirectLog = $redirected->statusLogs()->latest('id')->first();
    $assert($redirected->status === OrderStatus::ReturningToOffice && $redirected->return_office_id === $alternateOffice->id, 'admin redirect changes office without status change');
    $assert($redirectLog?->metadata['event'] === 'return_office_redirected', 'redirect-return writes audit metadata');
    $redirected->forceFill(['return_office_id' => $office->id])->save();
    app(FailedDeliveryService::class)->receiveReturn($officeStaff, $redirected);

    echo "Scenario 13: admin waiver allows zero-cash retrieval\n";
    $waivedOrder = $orderToPostPickup($uniquePhone(19), 'receiver');
    $waivedOrder = app(FailedDeliveryService::class)->markDeliveryFailedByDriver($driver, $waivedOrder, ReturnReason::ReceiverUnreachable);
    $waivedOrder = app(FailedDeliveryService::class)->receiveReturn($officeStaff, $waivedOrder);
    OfficeInventory::where('order_id', $waivedOrder->id)->update(['received_at' => now()->subDays(7)]);
    $waivedOrder = app(FailedDeliveryService::class)->waiveRetrievalFees($admin, $waivedOrder, '12.00', 'E2E full waiver');
    $waivedOrder = app(FailedDeliveryService::class)->retrieve($officeStaff, $waivedOrder, '0.00');
    $assert($waivedOrder->status === OrderStatus::RetrievedBySeller, 'full waiver allows zero-cash retrieval');
    $assert(bccomp((string) $waivedOrder->officeInventory->retrieval_fees_waived_amount, '12.00', 2) === 0, 'waiver amount is snapshotted on inventory');

    echo "Scenario 14: admin marks delivery failed from picked_up\n";
    $pickedOrder = app(AdminAssignmentService::class)->assign($admin, $createOrder($uniquePhone(20), 'receiver'), $driver, force: true);
    DB::transaction(function () use (&$pickedOrder, $driver): void {
        $pickedOrder = app(StateTransitionService::class)->transition(
            order: $pickedOrder->refresh(),
            to: OrderStatus::PickedUp,
            actorType: OrderActorType::Driver,
            actorId: $driver->id,
            metadata: ['event' => 'e2e_manual_pickup_transition'],
        );
    });
    $pickedOrder = app(FailedDeliveryService::class)->markDeliveryFailedByAdmin($admin, $pickedOrder, ReturnReason::AddressInvalid);
    $assert($pickedOrder->status === OrderStatus::ReturningToOffice && $pickedOrder->return_fault === ReturnFault::Sender, 'admin can fail earliest post-pickup state');
    app(FailedDeliveryService::class)->receiveReturn($officeStaff, $pickedOrder);

    echo "Scenario 15: abandonment cron snapshots final storage fee\n";
    $abandonedOrder = $orderToPostPickup($uniquePhone(21), 'receiver');
    $abandonedOrder = app(FailedDeliveryService::class)->markDeliveryFailedByDriver($driver, $abandonedOrder, ReturnReason::AddressInvalid);
    $abandonedOrder = app(FailedDeliveryService::class)->receiveReturn($officeStaff, $abandonedOrder);
    OfficeInventory::where('order_id', $abandonedOrder->id)->update(['received_at' => now()->subDays(30)]);
    app(AbandonStaleOrdersJob::class)->handle(app(FailedDeliveryService::class));
    $abandonedOrder->refresh();
    $assert($abandonedOrder->status === OrderStatus::Abandoned, 'abandonment cron flips stale office order to abandoned');
    $assert(bccomp((string) $abandonedOrder->storage_fee_accrued, '25.00', 2) === 0, 'abandonment snapshots final storage fee');

    echo "Scenario 16: excess cash retrieval is rejected\n";
    $excessOrder = $orderToPostPickup($uniquePhone(22), 'receiver');
    $excessOrder = app(FailedDeliveryService::class)->markDeliveryFailedByDriver($driver, $excessOrder, ReturnReason::ReceiverRefused);
    $excessOrder = app(FailedDeliveryService::class)->receiveReturn($officeStaff, $excessOrder);
    try {
        app(FailedDeliveryService::class)->retrieve($officeStaff, $excessOrder, '99.00');
        throw new RuntimeException('excess cash retrieval unexpectedly succeeded');
    } catch (OrderDomainException $exception) {
        $assert($exception->errorCode === OrderErrorCode::ExcessCashCollected, 'excess cash retrieval is rejected with expected error code');
    }

    echo "Scenario 17: auto-offline stale driver\n";
    $profile = DriverProfile::where('user_id', $driver->id)->firstOrFail();
    $profile->forceFill([
        'activity_status' => DriverActivityStatus::Online->value,
        'current_location' => Point::makeGeodetic($pickup['lat'], $pickup['lng']),
        'last_location_updated_at' => now()->subMinutes(6),
        'last_active_at' => now()->subMinutes(6),
    ])->save();
    $processed = app(AutoOfflineService::class)->process($profile);
    $profile->refresh();
    $presence = DriverPresenceLog::where('driver_id', $driver->id)->latest()->first();
    $assert($processed === 1 && $profile->activity_status === DriverActivityStatus::Offline, 'auto-offline flips stale driver offline');
    $assert($presence?->event === 'auto_offline' && $presence?->reason === 'gps_lost', 'auto-offline presence log written');

    // ─────────────────────────────────────────────────────────────────────
    // Settlement & Seller Payouts — milestone 2026-05-17
    // ─────────────────────────────────────────────────────────────────────

    PlatformSetting::set('payouts.clearance_hours', 48);
    PlatformSetting::set('payouts.min_amount', '20.00');

    $settlementSvc = app(SettlementService::class);
    $payoutSvc = app(SellerPayoutService::class);
    $reversalSvc = app(SettlementReversalService::class);

    // Helper: spin up a fresh driver with seeded bucket values.
    $freshDriver = static function (string $cash, string $earnings, string $debt) use ($uniquePhone, $makeUser): User {
        $d = $makeUser('SDriver', $uniquePhone(random_int(20, 9999)), 'driver');
        DriverProfile::create([
            'user_id' => $d->id,
            'office_id' => null,
            'status' => DriverStatus::Active->value,
            'vehicle_type' => VehicleType::Car->value,
            'vehicle_plate' => 'S-'.Str::upper(Str::random(4)),
            'activity_status' => DriverActivityStatus::Offline->value,
        ]);
        DriverAccount::create([
            'driver_id' => $d->id,
            'cash_to_deposit' => $cash,
            'earnings_balance' => $earnings,
            'debt_balance' => $debt,
            'max_cash_liability' => '1000.00',
        ]);

        return $d;
    };

    // Helper: spin up a sale order in delivered state with a seller_earning row.
    $freshEarning = static function (User $driver, User $seller, string $amount, string $status = 'pending_settlement', ?Carbon $clearedAt = null, ?Carbon $availableAt = null) use ($pickup, $dropoff): SellerEarning {
        $order = Order::create([
            'tracking_token' => (string) Str::ulid(),
            'order_type' => OrderType::P2pSale->value,
            'status' => OrderStatus::Delivered->value,
            'sender_user_id' => $seller->id,
            'sender_phone' => $seller->phone_number,
            'sender_name' => 'S',
            'pickup_address' => 'S pickup',
            'pickup_location' => Point::makeGeodetic($pickup['lat'], $pickup['lng']),
            'pickup_code' => '111111',
            'receiver_type' => 'guest',
            'receiver_phone' => '+218900000000',
            'receiver_name' => 'R',
            'receiver_address' => 'R drop',
            'receiver_location' => Point::makeGeodetic($dropoff['lat'], $dropoff['lng']),
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
            'order_id' => $order->id,
            'seller_user_id' => $seller->id,
            'amount' => $amount,
            'status' => $status,
            'cleared_at' => $clearedAt,
            'available_at' => $availableAt,
        ]);
    };

    echo "Scenario 18: settlement happy path match (all 3 buckets clear)\n";
    $d18 = $freshDriver('100.00', '30.00', '0.00');
    $settlement18 = $settlementSvc->process($d18, $officeStaff, $office, '70.00', '0.00', null);
    $d18->driverAccount()->getRelated()->where('driver_id', $d18->id)->first(); // dummy refresh
    $acct18 = DriverAccount::where('driver_id', $d18->id)->first();
    $assert($settlement18->status === SettlementStatus::Completed, 'settlement marked Completed');
    $assert((string) $acct18->cash_to_deposit === '0.00', 'cash_to_deposit cleared to 0');
    $assert((string) $acct18->earnings_balance === '0.00', 'earnings_balance cleared to 0');
    $assert((string) $acct18->debt_balance === '0.00', 'debt_balance cleared to 0');

    echo "Scenario 19: settlement empty buckets rejected\n";
    $d19 = $freshDriver('0.00', '0.00', '0.00');
    $caught19 = false;
    try {
        $settlementSvc->process($d19, $officeStaff, $office, '0.00', '0.00', null);
    } catch (EmptySettlementException $e) {
        $caught19 = $e->errorCode()->value === 'SETTLEMENT_EMPTY';
    }
    $assert($caught19, 'empty buckets throws SETTLEMENT_EMPTY');

    echo "Scenario 20: settlement excess rejected (422)\n";
    $d20 = $freshDriver('50.00', '0.00', '0.00');
    $caught20 = false;
    try {
        $settlementSvc->process($d20, $officeStaff, $office, '80.00', '0.00', null);
    } catch (SettlementExcessException $e) {
        $caught20 = $e->errorCode()->value === 'SETTLEMENT_EXCESS_REJECTED';
    }
    $assert($caught20, 'excess throws SETTLEMENT_EXCESS_REJECTED');

    echo "Scenario 21: settlement acknowledged shortage → debt_balance\n";
    $d21 = $freshDriver('100.00', '0.00', '0.00');
    $settlement21 = $settlementSvc->process($d21, $officeStaff, $office, '70.00', '0.00', 'short');
    $acct21 = DriverAccount::where('driver_id', $d21->id)->first();
    $assert((string) $settlement21->shortage_amount === '30.00', 'settlement shortage_amount = 30.00');
    $assert((string) $acct21->debt_balance === '30.00', 'shortage pushed to debt_balance');
    $assert((string) $acct21->cash_to_deposit === '0.00', 'cash bucket still cleared');

    echo "Scenario 22: settlement zero-net (cash + debt = earnings)\n";
    $d22 = $freshDriver('50.00', '50.00', '0.00');
    $settlement22 = $settlementSvc->process($d22, $officeStaff, $office, '0.00', '0.00', null);
    $assert($settlement22->cashMovement() === '0.00', 'no cash movement when buckets cancel');

    echo "Scenario 23: settlement flips pending_settlement earnings to pending_clearance\n";
    $d23 = $freshDriver('200.00', '0.00', '0.00');
    $seller23 = $makeUser('Seller23', $uniquePhone(23), 'user');
    $earningA = $freshEarning($d23, $seller23, '95.00');
    $earningB = $freshEarning($d23, $seller23, '47.50');
    $settlementSvc->process($d23, $officeStaff, $office, '200.00', '0.00', null);
    $earningA->refresh();
    $earningB->refresh();
    $assert($earningA->status === SellerEarningStatus::PendingClearance, 'earningA → pending_clearance');
    $assert($earningB->status === SellerEarningStatus::PendingClearance, 'earningB → pending_clearance');
    $assert($earningA->cleared_at !== null, 'cleared_at stamped');

    echo "Scenario 24: clearance cron flips eligible earnings to available\n";
    $d24 = $freshDriver('0.00', '0.00', '0.00');
    $seller24 = $makeUser('Seller24', $uniquePhone(24), 'user');
    $earning24 = $freshEarning($d24, $seller24, '60.00', 'pending_clearance', now()->subHours(49));
    (new ClearSellerEarningsJob)->handle();
    $earning24->refresh();
    $assert($earning24->status === SellerEarningStatus::Available, 'cron promoted earning to available');
    $assert($earning24->available_at !== null, 'available_at stamped');

    echo "Scenario 25: clearance cron skips ineligible (<48h)\n";
    $d25 = $freshDriver('0.00', '0.00', '0.00');
    $seller25 = $makeUser('Seller25', $uniquePhone(25), 'user');
    $earning25 = $freshEarning($d25, $seller25, '60.00', 'pending_clearance', now()->subHours(10));
    (new ClearSellerEarningsJob)->handle();
    $earning25->refresh();
    $assert($earning25->status === SellerEarningStatus::PendingClearance, 'cron left fresh earning untouched');

    echo "Scenario 26: payout happy path (3 earnings → paid_out)\n";
    $d26 = $freshDriver('0.00', '0.00', '0.00');
    $seller26 = $makeUser('Seller26', $uniquePhone(26), 'user');
    $eA = $freshEarning($d26, $seller26, '30.00', 'available', now()->subHours(72), now()->subHours(24));
    $eB = $freshEarning($d26, $seller26, '47.00', 'available', now()->subHours(72), now()->subHours(24));
    $eC = $freshEarning($d26, $seller26, '25.00', 'available', now()->subHours(72), now()->subHours(24));
    $payout = $payoutSvc->process(
        $seller26, $officeStaff, $office,
        collect([$eA->public_id, $eB->public_id, $eC->public_id]),
        '102.00',
    );
    $assert((string) $payout->amount === '102.00', 'payout amount = 102.00');
    $assert($payout->status === SellerPayoutStatus::Paid, 'payout status = paid');
    $assert($payout->orders()->count() === 3, 'payout linked to 3 orders via pivot');
    foreach ([$eA, $eB, $eC] as $e) {
        $e->refresh();
        $assert($e->status === SellerEarningStatus::PaidOut, "earning {$e->public_id} → paid_out");
    }

    echo "Scenario 27: payout partial selection\n";
    $d27 = $freshDriver('0.00', '0.00', '0.00');
    $seller27 = $makeUser('Seller27', $uniquePhone(27), 'user');
    $f1 = $freshEarning($d27, $seller27, '30.00', 'available', now()->subHours(72), now()->subHours(24));
    $f2 = $freshEarning($d27, $seller27, '47.00', 'available', now()->subHours(72), now()->subHours(24));
    $payoutSvc->process(
        $seller27, $officeStaff, $office,
        collect([$f1->public_id]),
        '30.00',
    );
    $f2->refresh();
    $assert($f2->status === SellerEarningStatus::Available, 'unselected earning stayed available');

    echo "Scenario 28: payout total mismatch (422)\n";
    $d28 = $freshDriver('0.00', '0.00', '0.00');
    $seller28 = $makeUser('Seller28', $uniquePhone(28), 'user');
    $g1 = $freshEarning($d28, $seller28, '30.00', 'available', now()->subHours(72), now()->subHours(24));
    $g2 = $freshEarning($d28, $seller28, '20.00', 'available', now()->subHours(72), now()->subHours(24));
    $caught28 = false;
    try {
        $payoutSvc->process($seller28, $officeStaff, $office, collect([$g1->public_id, $g2->public_id]), '100.00');
    } catch (PayoutValidationException $e) {
        $caught28 = $e->errorCode()->value === 'PAYOUT_TOTAL_MISMATCH';
    }
    $assert($caught28, 'payout total mismatch throws PAYOUT_TOTAL_MISMATCH');

    echo "Scenario 29: payout below minimum (422)\n";
    $d29 = $freshDriver('0.00', '0.00', '0.00');
    $seller29 = $makeUser('Seller29', $uniquePhone(29), 'user');
    $h1 = $freshEarning($d29, $seller29, '5.00', 'available', now()->subHours(72), now()->subHours(24));
    $caught29 = false;
    try {
        $payoutSvc->process($seller29, $officeStaff, $office, collect([$h1->public_id]), '5.00');
    } catch (PayoutValidationException $e) {
        $caught29 = $e->errorCode()->value === 'PAYOUT_BELOW_MINIMUM';
    }
    $assert($caught29, 'payout below minimum throws PAYOUT_BELOW_MINIMUM');

    echo "Scenario 30: settlement reversal happy path (earnings still pending_clearance)\n";
    $d30 = $freshDriver('100.00', '0.00', '0.00');
    $seller30 = $makeUser('Seller30', $uniquePhone(30), 'user');
    $eRev = $freshEarning($d30, $seller30, '95.00');
    $orig30 = $settlementSvc->process($d30, $officeStaff, $office, '100.00', '0.00', null);
    $correcting = $reversalSvc->reverse($orig30, $admin, 'Agent miscount caught at end of shift');
    $orig30->refresh();
    $eRev->refresh();
    $acct30 = DriverAccount::where('driver_id', $d30->id)->first();
    $assert($orig30->status === SettlementStatus::Cancelled, 'original settlement marked cancelled');
    $assert($correcting->status === SettlementStatus::Completed, 'correcting settlement marked completed');
    $assert($eRev->status === SellerEarningStatus::PendingSettlement, 'earning flipped back to pending_settlement');
    $assert((string) $acct30->cash_to_deposit === '100.00', 'cash bucket restored');

    echo "Scenario 31: settlement reversal blocked when earning past pending_clearance\n";
    $d31 = $freshDriver('100.00', '0.00', '0.00');
    $seller31 = $makeUser('Seller31', $uniquePhone(31), 'user');
    $eBlock = $freshEarning($d31, $seller31, '95.00');
    $orig31 = $settlementSvc->process($d31, $officeStaff, $office, '100.00', '0.00', null);
    // Advance earning to available to block reversal.
    SellerEarning::where('id', $eBlock->id)->update([
        'status' => SellerEarningStatus::Available->value,
        'available_at' => now(),
    ]);
    $caught31 = false;
    try {
        $reversalSvc->reverse($orig31, $admin, 'too late');
    } catch (SettlementNotReversibleException $e) {
        $caught31 = $e->errorCode()->value === 'SETTLEMENT_NOT_REVERSIBLE';
    }
    $assert($caught31, 'reversal blocked when any earning past pending_clearance');

    echo "Scenario 32: reversal restores debt correctly when settlement cleared old debt + recorded shortage\n";
    // Regression for codex finding: pre-existing debt 20 + cash 30, driver brings 0 → shortage 50.
    // Old buggy math computed debt = current_debt - (debt_cleared + shortage) = 50 - 70 = -20 → clamped 0.
    // Correct math: debt = current_debt - shortage + debt_cleared = 50 - 50 + 20 = 20.
    $d32 = $freshDriver('30.00', '0.00', '20.00');
    $seller32 = $makeUser('Seller32', $uniquePhone(32), 'user');
    $e32 = $freshEarning($d32, $seller32, '40.00');
    $orig32 = $settlementSvc->process($d32, $officeStaff, $office, '0.00', '0.00', null);
    $assert((string) $orig32->debt_balance_cleared === '20.00', 'settlement snapshotted old debt 20');
    $assert((string) $orig32->shortage_amount === '50.00', 'settlement recorded shortage 50');
    $acct32mid = DriverAccount::where('driver_id', $d32->id)->first();
    $assert((string) $acct32mid->debt_balance === '50.00', 'post-settlement debt equals shortage (50)');

    // Verify balance_after on the debt-clearing transaction is 0.00 (regression for finding #2).
    $debtClearTx = DriverAccountTransaction::query()
        ->where('driver_id', $d32->id)
        ->where('bucket', DriverAccountBucket::DebtBalance->value)
        ->where('reason', DriverAccountTransactionReason::Settlement->value)
        ->where('reference_id', $orig32->id)
        ->first();
    $assert($debtClearTx !== null, 'debt-clear settlement transaction recorded');
    $assert((string) $debtClearTx->balance_after === '0.00', 'debt-clear balance_after is 0.00 (not negative)');

    $reversalSvc->reverse($orig32, $admin, 'Regression scenario for debt+shortage reversal');
    $acct32 = DriverAccount::where('driver_id', $d32->id)->first();
    $assert((string) $acct32->cash_to_deposit === '30.00', 'cash bucket restored to 30');
    $assert((string) $acct32->earnings_balance === '0.00', 'earnings bucket restored to 0');
    $assert((string) $acct32->debt_balance === '20.00', 'debt bucket restored to original 20 (was clamped to 0 under bug)');

    // Pivot rows on the cancelled original are preserved for audit (regression for finding #3).
    $pivotCount32 = SettlementOrder::query()->where('settlement_id', $orig32->id)->count();
    $assert($pivotCount32 === 1, 'settlement_orders pivot rows preserved on reversal');

    echo "ALL ORDER E2E SMOKE SCENARIOS PASSED\n";
} finally {
    DB::rollBack();
}
