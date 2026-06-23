<?php

declare(strict_types=1);

use App\Enums\DeliveryFeeStatus;
use App\Enums\ItemSize;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\SellerPayoutStatus;
use App\Enums\SettlementStatus;
use App\Models\MerchantProfile;
use App\Models\Order;
use App\Models\Region;
use App\Models\Settlement;
use App\Models\User;
use App\Services\Reporting\FinanceReportService;
use Carbon\CarbonImmutable;
use Clickbar\Magellan\Data\Geometries\Point;
use Database\Seeders\PlatformSettingsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Support\TestWorld;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Build a minimal delivered order with explicit financial snapshots.
 * pickup_location defaults to inside the TestWorld region (32.88, 13.19).
 *
 * @param  array<string, mixed>  $overrides
 */
function makeDeliveredOrder(array $overrides = []): Order
{
    $sender = User::factory()->create();

    return Order::create(array_merge([
        'tracking_token' => (string) Str::ulid(),
        'order_type' => OrderType::StandardDelivery->value,
        'status' => OrderStatus::Delivered->value,
        'sender_user_id' => $sender->id,
        'sender_phone' => '+218910000000',
        'sender_name' => 'Sender',
        'pickup_address' => 'Test pickup',
        'pickup_location' => Point::makeGeodetic(32.8872, 13.1913),
        'pickup_code' => '111111',
        'receiver_type' => 'guest',
        'receiver_phone' => '+218910000001',
        'receiver_name' => 'Receiver',
        'receiver_address' => 'Test dropoff',
        'receiver_location' => Point::makeGeodetic(32.8882, 13.1923),
        'delivery_code' => '222222',
        'item_description' => 'Parcel',
        'item_size' => ItemSize::Small->value,
        'item_price' => '0.00',
        'commission_rate' => '0.0000',
        'commission_amount' => '0.00',
        'delivery_fee_base' => '10.00',
        'delivery_fee' => '10.00',
        'driver_fee_cut_rate' => '0.0200',
        'driver_fee_cut_amount' => '0.20',
        'delivery_fee_payer' => 'sender',
        'delivery_fee_payment_method' => 'cash',
        'delivery_fee_status' => DeliveryFeeStatus::Paid->value,
        'delivered_at' => now()->subMinute(),
        'status_changed_at' => now(),
    ], $overrides));
}

// Seed roles + platform settings (needed by TestWorld) once per test via beforeEach.
beforeEach(function (): void {
    config()->set('reporting.timezone', 'Africa/Tripoli');

    Artisan::call('db:seed', ['--class' => RolesSeeder::class, '--no-interaction' => true]);
    Artisan::call('db:seed', ['--class' => PlatformSettingsSeeder::class, '--no-interaction' => true]);

    // Build the standard TestWorld: one active service-area + region + office around Tripoli.
    $world = TestWorld::create();
    $this->office = $world['office'];
    $this->region = $world['region'];
    $this->pickup = $world['pickup'];
    $this->dropoff = $world['dropoff'];

    $this->service = app(FinanceReportService::class);
});

// ---------------------------------------------------------------------------
// 1. Accrued revenue: delivered-only + non-sale fee-cut counted
// ---------------------------------------------------------------------------

it('accrued counts only delivered orders and includes fee_cut for standard_delivery', function (): void {
    // Delivered standard_delivery: commission=0, fee_cut=2.00
    makeDeliveredOrder([
        'order_type' => OrderType::StandardDelivery->value,
        'commission_amount' => '0.00',
        'driver_fee_cut_amount' => '2.00',
    ]);

    // Delivered p2p_sale: commission=5.00, fee_cut=1.00
    makeDeliveredOrder([
        'order_type' => OrderType::P2pSale->value,
        'commission_amount' => '5.00',
        'driver_fee_cut_amount' => '1.00',
    ]);

    // Cancelled — must NOT count
    makeDeliveredOrder([
        'status' => OrderStatus::CancelledByUser->value,
        'commission_amount' => '99.00',
        'driver_fee_cut_amount' => '99.00',
        'delivered_at' => null,
    ]);

    // Assigned (in-flight) — must NOT count
    makeDeliveredOrder([
        'status' => OrderStatus::Assigned->value,
        'commission_amount' => '99.00',
        'driver_fee_cut_amount' => '99.00',
        'delivered_at' => null,
    ]);

    $result = $this->service->build('all', null);

    expect($result['accrued']['commission'])->toBe('5.00')
        ->and($result['accrued']['fee_cut'])->toBe('3.00')
        ->and($result['accrued']['total'])->toBe('8.00');
});

it('accrued amounts are returned as decimal strings', function (): void {
    makeDeliveredOrder([
        'commission_amount' => '5.00',
        'driver_fee_cut_amount' => '1.00',
    ]);

    $result = $this->service->build('all', null);

    expect($result['accrued']['commission'])->toBeString()
        ->and($result['accrued']['fee_cut'])->toBeString()
        ->and($result['accrued']['total'])->toBeString();
});

// ---------------------------------------------------------------------------
// 2. Delivery-period filter: bucket on delivered_at, NOT created_at
// ---------------------------------------------------------------------------

it('counts an order created before range but delivered inside, excludes one created inside but delivered after', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'Africa/Tripoli'));

    try {
        // created before today, delivered today → SHOULD count
        makeDeliveredOrder([
            'created_at' => CarbonImmutable::parse('2026-06-21 10:00:00', 'Africa/Tripoli')->utc(),
            'delivered_at' => CarbonImmutable::parse('2026-06-22 08:00:00', 'Africa/Tripoli')->utc(),
            'commission_amount' => '3.00',
            'driver_fee_cut_amount' => '1.00',
        ]);

        // created today, delivered tomorrow (null to simulate not-yet-delivered) → must NOT count
        makeDeliveredOrder([
            'status' => OrderStatus::Assigned->value,
            'created_at' => CarbonImmutable::parse('2026-06-22 09:00:00', 'Africa/Tripoli')->utc(),
            'delivered_at' => null,
            'commission_amount' => '99.00',
            'driver_fee_cut_amount' => '99.00',
        ]);

        $result = $this->service->build('today', null);

        expect($result['accrued']['total'])->toBe('4.00');
    } finally {
        CarbonImmutable::setTestNow();
    }
});

// ---------------------------------------------------------------------------
// 3. Cash: settlements + payouts
// ---------------------------------------------------------------------------

it('cash sums only completed settlements and paid payouts in range', function (): void {
    $twoMinutesAgo = now()->subMinutes(2)->toDateTimeString();
    $driverId = User::factory()->create()->id;
    $staffId = User::factory()->create()->id;
    $sellerId = User::factory()->create()->id;
    $officeId = $this->office->id;

    // Use DB::table() to set created_at explicitly, bypassing Eloquent auto-timestamps.
    // Completed settlement: received 100, paid 30, net = +70
    DB::table('settlements')->insert([
        'public_id' => (string) Str::ulid(),
        'driver_id' => $driverId,
        'office_id' => $officeId,
        'processed_by_staff_id' => $staffId,
        'cash_received_from_driver' => '100.00',
        'cash_paid_to_driver' => '30.00',
        'cash_to_deposit_cleared' => '0.00',
        'earnings_balance_cleared' => '0.00',
        'debt_balance_cleared' => '0.00',
        'shortage_amount' => '0.00',
        'excess_amount' => '0.00',
        'status' => SettlementStatus::Completed->value,
        'created_at' => $twoMinutesAgo,
        'updated_at' => $twoMinutesAgo,
    ]);

    // Disputed settlement — must NOT count
    DB::table('settlements')->insert([
        'public_id' => (string) Str::ulid(),
        'driver_id' => $driverId,
        'office_id' => $officeId,
        'processed_by_staff_id' => $staffId,
        'cash_received_from_driver' => '999.00',
        'cash_paid_to_driver' => '0.00',
        'cash_to_deposit_cleared' => '0.00',
        'earnings_balance_cleared' => '0.00',
        'debt_balance_cleared' => '0.00',
        'shortage_amount' => '0.00',
        'excess_amount' => '0.00',
        'status' => SettlementStatus::Disputed->value,
        'created_at' => $twoMinutesAgo,
        'updated_at' => $twoMinutesAgo,
    ]);

    // Cancelled settlement — must NOT count
    DB::table('settlements')->insert([
        'public_id' => (string) Str::ulid(),
        'driver_id' => $driverId,
        'office_id' => $officeId,
        'processed_by_staff_id' => $staffId,
        'cash_received_from_driver' => '888.00',
        'cash_paid_to_driver' => '0.00',
        'cash_to_deposit_cleared' => '0.00',
        'earnings_balance_cleared' => '0.00',
        'debt_balance_cleared' => '0.00',
        'shortage_amount' => '0.00',
        'excess_amount' => '0.00',
        'status' => SettlementStatus::Cancelled->value,
        'notes' => 'reversed',
        'created_at' => $twoMinutesAgo,
        'updated_at' => $twoMinutesAgo,
    ]);

    // Paid payout = 20.00 — bucket on paid_at
    DB::table('seller_payouts')->insert([
        'public_id' => (string) Str::ulid(),
        'user_id' => $sellerId,
        'office_id' => $officeId,
        'amount' => '20.00',
        'status' => SellerPayoutStatus::Paid->value,
        'paid_at' => $twoMinutesAgo,
        'payout_method' => 'cash_at_office',
        'created_at' => $twoMinutesAgo,
        'updated_at' => $twoMinutesAgo,
    ]);

    // Cancelled payout — must NOT count (status is the guard; paid_at is NOT NULL in schema)
    DB::table('seller_payouts')->insert([
        'public_id' => (string) Str::ulid(),
        'user_id' => $sellerId,
        'office_id' => $officeId,
        'amount' => '50.00',
        'status' => SellerPayoutStatus::Cancelled->value,
        'paid_at' => $twoMinutesAgo,
        'payout_method' => 'cash_at_office',
        'created_at' => $twoMinutesAgo,
        'updated_at' => $twoMinutesAgo,
    ]);

    $result = $this->service->build('all', null);

    // net = 70 (received 100 - paid 30), payouts = 20, cash.total = 70 - 20 = 50
    expect($result['cash']['settlement_cash_net'])->toBe('70.00')
        ->and($result['cash']['payouts'])->toBe('20.00')
        ->and($result['cash']['total'])->toBe('50.00');
});

// ---------------------------------------------------------------------------
// 4. Gap
// ---------------------------------------------------------------------------

it('gap = accrued.total − cash.total', function (): void {
    // Accrued = 8.00 (commission 5 + fee_cut 3)
    makeDeliveredOrder([
        'order_type' => OrderType::P2pSale->value,
        'commission_amount' => '5.00',
        'driver_fee_cut_amount' => '3.00',
    ]);

    // Cash.total = 5.00 (net settlement 5, payouts 0)
    $twoMinutesAgo = now()->subMinutes(2)->toDateTimeString();
    DB::table('settlements')->insert([
        'public_id' => (string) Str::ulid(),
        'driver_id' => User::factory()->create()->id,
        'office_id' => $this->office->id,
        'processed_by_staff_id' => User::factory()->create()->id,
        'cash_received_from_driver' => '5.00',
        'cash_paid_to_driver' => '0.00',
        'cash_to_deposit_cleared' => '0.00',
        'earnings_balance_cleared' => '0.00',
        'debt_balance_cleared' => '0.00',
        'shortage_amount' => '0.00',
        'excess_amount' => '0.00',
        'status' => SettlementStatus::Completed->value,
        'created_at' => $twoMinutesAgo,
        'updated_at' => $twoMinutesAgo,
    ]);

    $result = $this->service->build('all', null);

    expect($result['gap'])->toBe('3.00'); // 8.00 - 5.00
});

// ---------------------------------------------------------------------------
// 5. by_office: snapshot attribution (orders.pickup_office_id)
// ---------------------------------------------------------------------------

it('by_office attributes revenue to the snapshot office, no snapshot → unassigned', function (): void {
    // Snapshotted to $this->office at creation (commission=6, fee_cut=2 → 8.00 total)
    makeDeliveredOrder([
        'pickup_region_id' => $this->region->id,
        'pickup_office_id' => $this->office->id,
        'commission_amount' => '6.00',
        'driver_fee_cut_amount' => '2.00',
    ]);

    // No resolved office (pickup_office_id null) → unassigned
    makeDeliveredOrder([
        'pickup_office_id' => null,
        'commission_amount' => '3.00',
        'driver_fee_cut_amount' => '1.00',
    ]);

    $byOffice = collect($this->service->build('all', null)['by_office']);

    $assigned = $byOffice->firstWhere('office.public_id', $this->office->public_id);
    $unassigned = $byOffice->first(fn (array $r): bool => $r['office'] === 'unassigned');

    expect($assigned)->not->toBeNull()
        ->and($assigned['amount'])->toBe('8.00')
        ->and($unassigned)->not->toBeNull()
        ->and($unassigned['amount'])->toBe('4.00');
});

it('by_office exposes public office identity and never the internal id', function (): void {
    makeDeliveredOrder([
        'pickup_office_id' => $this->office->id,
        'commission_amount' => '6.00',
        'driver_fee_cut_amount' => '2.00',
    ]);

    $result = $this->service->build('all', null);
    $row = collect($result['by_office'])->firstWhere('office.public_id', $this->office->public_id);

    expect($row)->not->toBeNull()
        ->and($row['office'])->toHaveKeys(['public_id', 'name'])
        ->and($row['office'])->not->toHaveKey('id')
        ->and($row['office']['public_id'])->toBe($this->office->public_id);

    // The integer office id must not leak anywhere in the by_office payload.
    expect(json_encode($result['by_office']))
        ->not->toContain('"office_id"')
        ->not->toContain('"id":'.$this->office->id);
});

it('by_office reads the order snapshot and is stable if the region later changes', function (): void {
    makeDeliveredOrder([
        'pickup_region_id' => $this->region->id,
        'pickup_office_id' => $this->office->id,
        'commission_amount' => '6.00',
        'driver_fee_cut_amount' => '2.00',
    ]);

    // The region is later deactivated / re-drawn — attribution must NOT change,
    // because finance reads the order-time snapshot, not today's map.
    DB::table('regions')->where('id', $this->region->id)->update(['is_active' => false]);

    $row = collect($this->service->build('all', null)['by_office'])
        ->firstWhere('office.public_id', $this->office->public_id);

    expect($row)->not->toBeNull()->and($row['amount'])->toBe('8.00');
});

it('counts a delivered order once under its snapshot office (no double-count)', function (): void {
    makeDeliveredOrder([
        'pickup_office_id' => $this->office->id,
        'commission_amount' => '6.00',
        'driver_fee_cut_amount' => '2.00',
    ]);

    $byOffice = collect($this->service->build('all', null)['by_office']);

    expect($byOffice->where('office.public_id', $this->office->public_id))->toHaveCount(1)
        ->and($byOffice->firstWhere('office.public_id', $this->office->public_id)['amount'])->toBe('8.00');
});

// ---------------------------------------------------------------------------
// 6. office_id filter scopes revenue queries
// ---------------------------------------------------------------------------

it('office_id filter restricts accrued to the snapshot office', function (): void {
    // Snapshotted to $this->office
    makeDeliveredOrder([
        'pickup_office_id' => $this->office->id,
        'commission_amount' => '5.00',
        'driver_fee_cut_amount' => '1.00',
    ]);

    // No office snapshot — must not appear when filtering by $this->office->id
    makeDeliveredOrder([
        'pickup_office_id' => null,
        'commission_amount' => '99.00',
        'driver_fee_cut_amount' => '99.00',
    ]);

    $result = $this->service->build('all', $this->office->id);

    expect($result['accrued']['commission'])->toBe('5.00')
        ->and($result['accrued']['fee_cut'])->toBe('1.00')
        ->and($result['accrued']['total'])->toBe('6.00');
});

// ---------------------------------------------------------------------------
// 7. by_merchant: top merchant_delivery by platform revenue
// ---------------------------------------------------------------------------

it('by_merchant lists merchant_delivery orders grouped by merchant', function (): void {
    $merchantUser = User::factory()->create();
    $merchant = MerchantProfile::create([
        'user_id' => $merchantUser->id,
        'business_name' => 'Test Merchant',
        'status' => 'active',
    ]);

    // Two merchant_delivery orders for same merchant
    makeDeliveredOrder([
        'order_type' => OrderType::MerchantDelivery->value,
        'merchant_profile_id' => $merchant->id,
        'commission_amount' => '4.00',
        'driver_fee_cut_amount' => '1.00',
        'pickup_location' => Point::makeGeodetic($this->pickup['lat'], $this->pickup['lng']),
    ]);

    makeDeliveredOrder([
        'order_type' => OrderType::MerchantDelivery->value,
        'merchant_profile_id' => $merchant->id,
        'commission_amount' => '3.00',
        'driver_fee_cut_amount' => '1.00',
        'pickup_location' => Point::makeGeodetic($this->pickup['lat'], $this->pickup['lng']),
    ]);

    // Non-merchant order — should not appear in by_merchant
    makeDeliveredOrder([
        'order_type' => OrderType::StandardDelivery->value,
        'commission_amount' => '0.00',
        'driver_fee_cut_amount' => '2.00',
    ]);

    $result = $this->service->build('all', null);
    $byMerchant = $result['by_merchant'];

    expect($byMerchant)->toHaveCount(1);
    expect($byMerchant[0]['merchant']['public_id'])->toBe($merchant->public_id)
        ->and($byMerchant[0]['merchant']['name'])->toBe('Test Merchant')
        ->and($byMerchant[0]['amount'])->toBe('9.00'); // 4+1+3+1
});

// ---------------------------------------------------------------------------
// 8. daily_trend: group on sqlLocalDate(delivered_at)
// ---------------------------------------------------------------------------

it('daily_trend groups delivered revenue by local date', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'Africa/Tripoli'));

    try {
        makeDeliveredOrder([
            'delivered_at' => CarbonImmutable::parse('2026-06-22 08:00:00', 'Africa/Tripoli')->utc(),
            'commission_amount' => '5.00',
            'driver_fee_cut_amount' => '1.00',
        ]);

        makeDeliveredOrder([
            'delivered_at' => CarbonImmutable::parse('2026-06-21 10:00:00', 'Africa/Tripoli')->utc(),
            'commission_amount' => '3.00',
            'driver_fee_cut_amount' => '0.00',
        ]);

        $result = $this->service->build('7d', null);
        $trend = collect($result['daily_trend'])->keyBy('date');

        expect($trend->has('2026-06-22'))->toBeTrue()
            ->and($trend['2026-06-22']['amount'])->toBe('6.00')
            ->and($trend->has('2026-06-21'))->toBeTrue()
            ->and($trend['2026-06-21']['amount'])->toBe('3.00');
    } finally {
        CarbonImmutable::setTestNow();
    }
});

// ---------------------------------------------------------------------------
// 9. recent_orders: latest ~12 delivered in-range, correct shape
// ---------------------------------------------------------------------------

it('recent_orders returns latest 12 delivered orders with correct shape', function (): void {
    foreach (range(1, 13) as $i) {
        makeDeliveredOrder([
            'commission_amount' => '1.00',
            'driver_fee_cut_amount' => '0.50',
            'delivered_at' => now()->subMinutes($i),
        ]);
    }

    $result = $this->service->build('all', null);
    $recent = $result['recent_orders'];

    expect($recent)->toHaveCount(12);

    $first = $recent[0];
    expect($first)->toHaveKeys(['order_public_id', 'type', 'merchant', 'item_value', 'commission_amount', 'driver_fee_cut_amount', 'platform_revenue']);
    expect($first['platform_revenue'])->toBe('1.50'); // 1.00 + 0.50
    expect($first['merchant'])->toBeNull(); // standard_delivery
});

it('recent_orders includes merchant info for merchant_delivery orders', function (): void {
    $merchantUser = User::factory()->create();
    $merchant = MerchantProfile::create([
        'user_id' => $merchantUser->id,
        'business_name' => 'Merchant Co',
        'status' => 'active',
    ]);

    makeDeliveredOrder([
        'order_type' => OrderType::MerchantDelivery->value,
        'merchant_profile_id' => $merchant->id,
        'commission_amount' => '2.00',
        'driver_fee_cut_amount' => '0.50',
    ]);

    $result = $this->service->build('all', null);
    $recent = $result['recent_orders'];

    expect($recent[0]['merchant'])->toBe([
        'public_id' => $merchant->public_id,
        'name' => 'Merchant Co',
    ]);
    expect($recent[0]['platform_revenue'])->toBe('2.50');
});

// ---------------------------------------------------------------------------
// 10. by_source shape
// ---------------------------------------------------------------------------

it('by_source has commission and fee_cut keys', function (): void {
    makeDeliveredOrder([
        'order_type' => OrderType::P2pSale->value,
        'commission_amount' => '4.00',
        'driver_fee_cut_amount' => '2.00',
    ]);

    $result = $this->service->build('all', null);
    $keys = array_column($result['by_source'], 'key');

    expect($keys)->toContain('commission')
        ->and($keys)->toContain('fee_cut');

    $byKey = collect($result['by_source'])->keyBy('key');
    expect($byKey['commission']['amount'])->toBe('4.00')
        ->and($byKey['fee_cut']['amount'])->toBe('2.00');
});

// ---------------------------------------------------------------------------
// 11. HTTP endpoint — GET /api/admin/finance/report
// ---------------------------------------------------------------------------

function actingAsFinanceAdmin(): void
{
    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create(['must_change_password' => false]);
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);
}

it('admin gets 200 with expected JSON structure', function (): void {
    actingAsFinanceAdmin();

    $this->getJson('/api/admin/finance/report')
        ->assertOk()
        ->assertJsonStructure([
            'range',
            'office',
            'accrued' => ['total', 'commission', 'fee_cut'],
            'cash' => ['total', 'settlement_cash_net', 'payouts'],
            'gap',
            'by_source',
            'by_merchant',
            'by_office',
            'daily_trend',
            'recent_orders',
        ]);
});

it('non-admin gets 403', function (): void {
    Role::findOrCreate('admin', 'web');
    $user = User::factory()->create(['must_change_password' => false]);
    Sanctum::actingAs($user);

    $this->getJson('/api/admin/finance/report')->assertForbidden();
});

it('bogus range value returns 422', function (): void {
    actingAsFinanceAdmin();

    $this->getJson('/api/admin/finance/report?range=bogus')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['range']);
});

it('unknown office_id returns 422 from exists rule', function (): void {
    actingAsFinanceAdmin();

    $this->getJson('/api/admin/finance/report?office_id=01JDOESNOTEXIST')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['office_id']);
});

it('valid office_id returns 200 and office.public_id echoes back', function (): void {
    actingAsFinanceAdmin();

    // $this->office is populated by the beforeEach TestWorld::create() call
    $this->getJson('/api/admin/finance/report?office_id='.$this->office->public_id)
        ->assertOk()
        ->assertJsonPath('office.public_id', $this->office->public_id);
});
