<?php

declare(strict_types=1);

use App\Enums\AccountStatus;
use App\Enums\DriverActivityStatus;
use App\Enums\DriverStatus;
use App\Enums\DriverStrikeIssuer;
use App\Enums\DriverStrikeReason;
use App\Enums\ModerationAction;
use App\Enums\ModerationReason;
use App\Enums\SellerPayoutStatus;
use App\Enums\SettlementStatus;
use App\Enums\VehicleType;
use App\Models\AccountModerationAction;
use App\Models\DriverAccount;
use App\Models\DriverAccountTransaction;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\DriverStrike;
use App\Models\MerchantProfile;
use App\Models\OfficeInventory;
use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\SellerPayout;
use App\Models\Settlement;
use App\Models\User;
use App\Services\Reporting\StaffActivityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TestWorld;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Shared test world setup
// ---------------------------------------------------------------------------

beforeEach(function (): void {
    $world = TestWorld::create();
    $this->office = $world['office'];

    // Admin (the actor whose timeline we are building)
    $this->admin = User::factory()->create([
        'first_name' => 'Alice',
        'last_name' => 'Admin',
        'account_status' => AccountStatus::Active->value,
    ]);
    $this->admin->assignRole('admin');

    // A second admin who must NOT appear in Alice's timeline
    $this->otherAdmin = User::factory()->create([
        'account_status' => AccountStatus::Active->value,
    ]);
    $this->otherAdmin->assignRole('admin');

    $this->service = app(StaffActivityService::class);

    // ── Helpers ──────────────────────────────────────────────────────────────

    $this->makeDriver = function (): User {
        $u = User::factory()->create([
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'account_status' => AccountStatus::Active->value,
        ]);
        $u->assignRole('driver');

        DriverProfile::create([
            'user_id' => $u->id,
            'status' => DriverStatus::Active->value,
            'vehicle_type' => VehicleType::Motorcycle->value,
            'vehicle_plate' => 'D-'.strtoupper(Str::random(4)),
            'activity_status' => DriverActivityStatus::Offline->value,
        ]);

        DriverAccount::create([
            'driver_id' => $u->id,
            'cash_to_deposit' => '0.00',
            'earnings_balance' => '0.00',
            'debt_balance' => '0.00',
            'max_cash_liability' => '1000.00',
        ]);

        return $u;
    };

    $this->makeOrder = fn (): Order => Order::factory()->create();

    // Pre-create the shared driver for tests that don't need a custom one.
    $this->driver = ($this->makeDriver)();
});

// ---------------------------------------------------------------------------
// Helpers (closures assigned in beforeEach to access RefreshDatabase state)
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// order_action — actor_type filter
// ---------------------------------------------------------------------------

it('includes order_status_log with actor_type admin as order_action', function (): void {
    $order = ($this->makeOrder)();
    DB::table('order_status_logs')->insert([
        'order_id' => $order->id,
        'actor_type' => 'admin',
        'actor_id' => $this->admin->id,
        'from_status' => 'created',
        'to_status' => 'assigned',
        'created_at' => now(),
    ]);

    $items = $this->service->timeline($this->admin);
    $kinds = array_column($items, 'kind');

    expect($kinds)->toContain('order_action');

    $item = collect($items)->firstWhere('kind', 'order_action');
    expect($item['order']['public_id'])->toBe($order->public_id);
    expect($item['from_status'])->toBe('created');
    expect($item['to_status'])->toBe('assigned');
    expect($item['actor']['public_id'])->toBe($this->admin->public_id);
});

it('includes order_status_log with actor_type office_staff as order_action', function (): void {
    $order = ($this->makeOrder)();
    DB::table('order_status_logs')->insert([
        'order_id' => $order->id,
        'actor_type' => 'office_staff',
        'actor_id' => $this->admin->id,
        'from_status' => null,
        'to_status' => 'created',
        'created_at' => now(),
    ]);

    $items = $this->service->timeline($this->admin, ['order_action']);
    expect(array_column($items, 'kind'))->toContain('order_action');
});

it('does NOT include order_status_log with actor_type user even for the same actor_id', function (): void {
    $order = ($this->makeOrder)();
    DB::table('order_status_logs')->insert([
        'order_id' => $order->id,
        'actor_type' => 'user',          // should be filtered out
        'actor_id' => $this->admin->id,
        'from_status' => 'created',
        'to_status' => 'cancelled',
        'created_at' => now(),
    ]);

    $items = $this->service->timeline($this->admin, ['order_action']);
    expect($items)->toHaveCount(0);
});

// ---------------------------------------------------------------------------
// settlement_processed
// ---------------------------------------------------------------------------

it('surfaces a settlement processed by the staff', function (): void {
    $settlement = Settlement::create([
        'driver_id' => $this->driver->id,
        'office_id' => $this->office->id,
        'processed_by_staff_id' => $this->admin->id,
        'cash_received_from_driver' => '100.00',
        'cash_paid_to_driver' => '0.00',
        'status' => SettlementStatus::Completed->value,
    ]);

    $items = $this->service->timeline($this->admin, ['settlement_processed']);
    expect($items)->toHaveCount(1);

    $item = $items[0];
    expect($item['kind'])->toBe('settlement_processed');
    expect($item['settlement']['public_id'])->toBe($settlement->public_id);
    expect($item['driver']['public_id'])->toBe($this->driver->public_id);
    expect($item['cash_received_from_driver'])->toBe('100.00');
    expect($item)->not->toHaveKey('driver_id');
});

it('does not show a settlement processed by another staff', function (): void {
    Settlement::create([
        'driver_id' => $this->driver->id,
        'office_id' => $this->office->id,
        'processed_by_staff_id' => $this->otherAdmin->id,
        'cash_received_from_driver' => '50.00',
        'cash_paid_to_driver' => '0.00',
        'status' => SettlementStatus::Completed->value,
    ]);

    $items = $this->service->timeline($this->admin, ['settlement_processed']);
    expect($items)->toHaveCount(0);
});

// ---------------------------------------------------------------------------
// seller_payout_paid
// ---------------------------------------------------------------------------

it('surfaces a seller payout paid by the staff', function (): void {
    $seller = User::factory()->create(['first_name' => 'Sara', 'last_name' => 'Seller']);
    $seller->assignRole('user');

    $payout = SellerPayout::create([
        'user_id' => $seller->id,
        'office_id' => $this->office->id,
        'amount' => '75.00',
        'status' => SellerPayoutStatus::Paid->value,
        'paid_by_staff_id' => $this->admin->id,
        'paid_at' => now(),
    ]);

    $items = $this->service->timeline($this->admin, ['seller_payout_paid']);
    expect($items)->toHaveCount(1);

    $item = $items[0];
    expect($item['kind'])->toBe('seller_payout_paid');
    expect($item['payout']['public_id'])->toBe($payout->public_id);
    expect($item['seller']['public_id'])->toBe($seller->public_id);
    expect($item['seller']['name'])->toContain('Sara');
    expect($item['amount'])->toBe('75.00');
    expect($item)->not->toHaveKey('user_id');
});

// ---------------------------------------------------------------------------
// account_moderation
// ---------------------------------------------------------------------------

it('surfaces an account moderation action by the staff', function (): void {
    $target = User::factory()->create(['first_name' => 'Bob', 'last_name' => 'Target']);

    AccountModerationAction::factory()->create([
        'actor_id' => $this->admin->id,
        'user_id' => $target->id,
        'action' => ModerationAction::Suspend->value,
        'reason_code' => ModerationReason::Other->value,
    ]);

    $items = $this->service->timeline($this->admin, ['account_moderation']);
    expect($items)->toHaveCount(1);

    $item = $items[0];
    expect($item['kind'])->toBe('account_moderation');
    expect($item['target']['public_id'])->toBe($target->public_id);
    expect($item['target']['name'])->toContain('Bob');
    expect($item['action'])->toBe(ModerationAction::Suspend->value);
    expect($item['reason_code'])->toBe(ModerationReason::Other->value);
    expect($item)->not->toHaveKey('user_id');
    expect($item)->not->toHaveKey('actor_id');
});

// ---------------------------------------------------------------------------
// driver_account_adjustment
// ---------------------------------------------------------------------------

it('surfaces a driver account adjustment created by the staff', function (): void {
    DriverAccountTransaction::create([
        'driver_id' => $this->driver->id,
        'bucket' => 'debt_balance',
        'amount' => '-20.00',
        'reason' => 'manual_adjustment',
        'balance_after' => '0.00',
        'created_by_admin_id' => $this->admin->id,
    ]);

    $items = $this->service->timeline($this->admin, ['driver_account_adjustment']);
    expect($items)->toHaveCount(1);

    $item = $items[0];
    expect($item['kind'])->toBe('driver_account_adjustment');
    expect($item['driver']['public_id'])->toBe($this->driver->public_id);
    expect($item['bucket'])->toBe('debt_balance');
    expect($item['amount'])->toBe('-20.00');
    expect($item['reason'])->toBe('manual_adjustment');
    expect($item)->not->toHaveKey('driver_id');
});

// ---------------------------------------------------------------------------
// driver_strike_issued
// ---------------------------------------------------------------------------

it('surfaces a driver strike issued by the staff', function (): void {
    DriverStrike::create([
        'driver_id' => $this->driver->id,
        'reason' => DriverStrikeReason::ManualAdmin->value,
        'fee_amount' => '10.00',
        'issued_by' => DriverStrikeIssuer::Admin->value,
        'issued_by_admin_id' => $this->admin->id,
    ]);

    $items = $this->service->timeline($this->admin, ['driver_strike_issued']);
    expect($items)->toHaveCount(1);

    $item = $items[0];
    expect($item['kind'])->toBe('driver_strike_issued');
    expect($item['driver']['public_id'])->toBe($this->driver->public_id);
    expect($item['reason'])->toBe(DriverStrikeReason::ManualAdmin->value);
    expect($item['fee_amount'])->toBe('10.00');
    expect($item)->not->toHaveKey('driver_id');
});

// ---------------------------------------------------------------------------
// driver_strike_voided
// ---------------------------------------------------------------------------

it('surfaces a driver strike voided by the staff', function (): void {
    $strike = DriverStrike::create([
        'driver_id' => $this->driver->id,
        'reason' => DriverStrikeReason::ManualAdmin->value,
        'fee_amount' => '5.00',
        'issued_by' => DriverStrikeIssuer::System->value,
        'is_voided' => true,
        'voided_at' => now(),
        'voided_by_admin_id' => $this->admin->id,
        'void_reason' => 'Issued in error',
    ]);

    $items = $this->service->timeline($this->admin, ['driver_strike_voided']);
    expect($items)->toHaveCount(1);

    $item = $items[0];
    expect($item['kind'])->toBe('driver_strike_voided');
    expect($item['strike']['public_id'])->toBe($strike->public_id);
    expect($item['driver']['public_id'])->toBe($this->driver->public_id);
    expect($item['void_reason'])->toBe('Issued in error');
    expect($item)->not->toHaveKey('driver_id');
});

it('does NOT include a non-voided strike in driver_strike_voided', function (): void {
    DriverStrike::create([
        'driver_id' => $this->driver->id,
        'reason' => DriverStrikeReason::ManualAdmin->value,
        'fee_amount' => '0.00',
        'issued_by' => DriverStrikeIssuer::Admin->value,
        'issued_by_admin_id' => $this->admin->id,
        'voided_by_admin_id' => $this->admin->id,
        // voided_at is intentionally null
    ]);

    $items = $this->service->timeline($this->admin, ['driver_strike_voided']);
    expect($items)->toHaveCount(0);
});

// ---------------------------------------------------------------------------
// office_return_received
// ---------------------------------------------------------------------------

it('surfaces an office return received by the staff', function (): void {
    $order = ($this->makeOrder)();
    OfficeInventory::create([
        'order_id' => $order->id,
        'office_id' => $this->office->id,
        'received_by_staff_id' => $this->admin->id,
        'received_at' => now(),
    ]);

    $items = $this->service->timeline($this->admin, ['office_return_received']);
    expect($items)->toHaveCount(1);

    $item = $items[0];
    expect($item['kind'])->toBe('office_return_received');
    expect($item['order']['public_id'])->toBe($order->public_id);
});

// ---------------------------------------------------------------------------
// office_order_retrieved
// ---------------------------------------------------------------------------

it('surfaces an office order retrieved by the staff', function (): void {
    $order = ($this->makeOrder)();
    OfficeInventory::create([
        'order_id' => $order->id,
        'office_id' => $this->office->id,
        'received_by_staff_id' => $this->otherAdmin->id,
        'received_at' => now()->subDays(2),
        'retrieved_by_staff_id' => $this->admin->id,
        'retrieved_at' => now(),
    ]);

    $items = $this->service->timeline($this->admin, ['office_order_retrieved']);
    expect($items)->toHaveCount(1);

    $item = $items[0];
    expect($item['kind'])->toBe('office_order_retrieved');
    expect($item['order']['public_id'])->toBe($order->public_id);
});

// ---------------------------------------------------------------------------
// driver_approved (latest-pointer)
// ---------------------------------------------------------------------------

it('surfaces a driver profile approved by the staff', function (): void {
    $dp = DriverProfile::query()->where('user_id', $this->driver->id)->firstOrFail();
    $dp->forceFill([
        'approved_by_admin_id' => $this->admin->id,
        'approved_at' => now(),
        'status' => DriverStatus::Active->value,
    ])->save();

    $items = $this->service->timeline($this->admin, ['driver_approved']);
    expect($items)->toHaveCount(1);

    $item = $items[0];
    expect($item['kind'])->toBe('driver_approved');
    expect($item['driver']['public_id'])->toBe($this->driver->public_id);
});

it('skips a driver profile where approved_at is null', function (): void {
    $dp = DriverProfile::query()->where('user_id', $this->driver->id)->firstOrFail();
    $dp->forceFill([
        'approved_by_admin_id' => $this->admin->id,
        'approved_at' => null,
    ])->save();

    $items = $this->service->timeline($this->admin, ['driver_approved']);
    expect($items)->toHaveCount(0);
});

// ---------------------------------------------------------------------------
// driver_document_verified (latest-pointer)
// ---------------------------------------------------------------------------

it('surfaces a verified driver document', function (): void {
    DriverDocument::create([
        'driver_id' => $this->driver->id,
        'document_type' => 'national_id_front',
        'verified' => true,
        'verified_by_admin_id' => $this->admin->id,
        'verified_at' => now(),
    ]);

    $items = $this->service->timeline($this->admin, ['driver_document_verified']);
    expect($items)->toHaveCount(1);

    $item = $items[0];
    expect($item['kind'])->toBe('driver_document_verified');
    expect($item['driver']['public_id'])->toBe($this->driver->public_id);
    expect($item['document_type'])->toBe('national_id_front');
});

// ---------------------------------------------------------------------------
// merchant_onboarded (latest-pointer)
// ---------------------------------------------------------------------------

it('surfaces a merchant profile onboarded by the staff', function (): void {
    $merchant = MerchantProfile::factory()->create([
        'created_by_admin_id' => $this->admin->id,
        'approved_by_admin_id' => null,
        'approved_at' => null,
    ]);

    $items = $this->service->timeline($this->admin, ['merchant_onboarded']);
    expect($items)->toHaveCount(1);

    $item = $items[0];
    expect($item['kind'])->toBe('merchant_onboarded');
    expect($item['merchant']['public_id'])->toBe($merchant->public_id);
    expect($item['merchant']['name'])->toBe($merchant->business_name);
});

// ---------------------------------------------------------------------------
// merchant_approved (latest-pointer)
// ---------------------------------------------------------------------------

it('surfaces a merchant profile approved by the staff', function (): void {
    $merchant = MerchantProfile::factory()->create([
        'approved_by_admin_id' => $this->admin->id,
        'approved_at' => now(),
    ]);

    $items = $this->service->timeline($this->admin, ['merchant_approved']);
    expect($items)->toHaveCount(1);

    $item = $items[0];
    expect($item['kind'])->toBe('merchant_approved');
    expect($item['merchant']['public_id'])->toBe($merchant->public_id);
});

it('skips a merchant profile where approved_at is null', function (): void {
    MerchantProfile::factory()->create([
        'approved_by_admin_id' => $this->admin->id,
        'approved_at' => null,
    ]);

    $items = $this->service->timeline($this->admin, ['merchant_approved']);
    expect($items)->toHaveCount(0);
});

// ---------------------------------------------------------------------------
// setting_updated — key present, value ABSENT
// ---------------------------------------------------------------------------

it('surfaces a platform setting updated by the staff with key but no value', function (): void {
    PlatformSetting::set('some.setting', 'secret_value', $this->admin->id);

    $items = $this->service->timeline($this->admin, ['setting_updated']);
    expect($items)->toHaveCount(1);

    $item = $items[0];
    expect($item['kind'])->toBe('setting_updated');
    expect($item)->toHaveKey('key');
    expect($item['key'])->toBe('some.setting');
    // CRITICAL: value must NOT be present
    expect($item)->not->toHaveKey('value');
});

// ---------------------------------------------------------------------------
// order_abandoned (latest-pointer)
// ---------------------------------------------------------------------------

it('surfaces an order abandoned by the staff', function (): void {
    $order = ($this->makeOrder)();
    OfficeInventory::create([
        'order_id' => $order->id,
        'office_id' => $this->office->id,
        'received_by_staff_id' => $this->otherAdmin->id,
        'received_at' => now()->subDays(35),
        'abandoned_by_admin_id' => $this->admin->id,
        'abandoned_at' => now(),
    ]);

    $items = $this->service->timeline($this->admin, ['order_abandoned']);
    expect($items)->toHaveCount(1);

    $item = $items[0];
    expect($item['kind'])->toBe('order_abandoned');
    expect($item['order']['public_id'])->toBe($order->public_id);
});

it('skips an office_inventory row where abandoned_at is null for order_abandoned', function (): void {
    $order = ($this->makeOrder)();
    OfficeInventory::create([
        'order_id' => $order->id,
        'office_id' => $this->office->id,
        'received_by_staff_id' => $this->otherAdmin->id,
        'received_at' => now(),
        'abandoned_by_admin_id' => $this->admin->id,
        'abandoned_at' => null,
    ]);

    $items = $this->service->timeline($this->admin, ['order_abandoned']);
    expect($items)->toHaveCount(0);
});

// ---------------------------------------------------------------------------
// Merge & sort — items come back newest-first
// ---------------------------------------------------------------------------

it('returns items sorted newest-first by occurred_at', function (): void {
    $older = Carbon::now()->subMinutes(60)->toDateTimeString();
    $newer = Carbon::now()->subMinutes(5)->toDateTimeString();

    // Settlement: older timestamp
    DB::table('settlements')->insert([
        'public_id' => (string) Str::ulid(),
        'driver_id' => $this->driver->id,
        'office_id' => $this->office->id,
        'processed_by_staff_id' => $this->admin->id,
        'cash_received_from_driver' => '10.00',
        'cash_paid_to_driver' => '0.00',
        'cash_to_deposit_cleared' => '0.00',
        'earnings_balance_cleared' => '0.00',
        'debt_balance_cleared' => '0.00',
        'shortage_amount' => '0.00',
        'excess_amount' => '0.00',
        'status' => SettlementStatus::Completed->value,
        'created_at' => $older,
        'updated_at' => $older,
    ]);

    // Driver account adjustment: newer timestamp
    DB::table('driver_account_transactions')->insert([
        'driver_id' => $this->driver->id,
        'bucket' => 'earnings_balance',
        'amount' => '5.00',
        'reason' => 'manual_adjustment',
        'balance_after' => '5.00',
        'created_by_admin_id' => $this->admin->id,
        'created_at' => $newer,
        'updated_at' => $newer,
    ]);

    $items = $this->service->timeline($this->admin, ['settlement_processed', 'driver_account_adjustment']);
    expect($items)->toHaveCount(2);
    expect($items[0]['kind'])->toBe('driver_account_adjustment');
    expect($items[1]['kind'])->toBe('settlement_processed');
});

// ---------------------------------------------------------------------------
// $kinds filter restricts which sources run
// ---------------------------------------------------------------------------

it('returns only the requested kinds when $kinds filter is supplied', function (): void {
    // Seed one settlement and one moderation action for the admin.
    Settlement::create([
        'driver_id' => $this->driver->id,
        'office_id' => $this->office->id,
        'processed_by_staff_id' => $this->admin->id,
        'cash_received_from_driver' => '20.00',
        'cash_paid_to_driver' => '0.00',
        'status' => SettlementStatus::Completed->value,
    ]);

    $target = User::factory()->create();
    AccountModerationAction::factory()->create([
        'actor_id' => $this->admin->id,
        'user_id' => $target->id,
    ]);

    // Ask only for settlements.
    $items = $this->service->timeline($this->admin, ['settlement_processed']);
    $kinds = array_unique(array_column($items, 'kind'));

    expect($kinds)->toBe(['settlement_processed']);
    expect($items)->toHaveCount(1);
});

it('returns empty array when $kinds filter contains no valid kinds', function (): void {
    Settlement::create([
        'driver_id' => $this->driver->id,
        'office_id' => $this->office->id,
        'processed_by_staff_id' => $this->admin->id,
        'cash_received_from_driver' => '20.00',
        'cash_paid_to_driver' => '0.00',
        'status' => SettlementStatus::Completed->value,
    ]);

    $items = $this->service->timeline($this->admin, ['nonexistent_kind']);
    expect($items)->toHaveCount(0);
});

// ---------------------------------------------------------------------------
// Actor fields use public_id and name — no internal ids
// ---------------------------------------------------------------------------

it('actor field carries public_id and name, not the integer id', function (): void {
    Settlement::create([
        'driver_id' => $this->driver->id,
        'office_id' => $this->office->id,
        'processed_by_staff_id' => $this->admin->id,
        'cash_received_from_driver' => '30.00',
        'cash_paid_to_driver' => '0.00',
        'status' => SettlementStatus::Completed->value,
    ]);

    $items = $this->service->timeline($this->admin, ['settlement_processed']);
    $item = $items[0];

    expect($item['actor'])->toHaveKey('public_id');
    expect($item['actor'])->toHaveKey('name');
    expect($item['actor'])->not->toHaveKey('id');
    expect($item['actor']['public_id'])->toBe($this->admin->public_id);
    expect($item['actor']['name'])->toContain('Alice');
});

// ---------------------------------------------------------------------------
// HTTP endpoint — GET /api/admin/staff/{staff}/activity
// ---------------------------------------------------------------------------

it('returns 200 paginated activity for an admin', function (): void {
    // Seed one settlement so there is at least one item
    Settlement::create([
        'driver_id' => $this->driver->id,
        'office_id' => $this->office->id,
        'processed_by_staff_id' => $this->admin->id,
        'cash_received_from_driver' => '50.00',
        'cash_paid_to_driver' => '0.00',
        'status' => SettlementStatus::Completed->value,
    ]);

    $this->actingAs($this->admin)
        ->getJson("/api/admin/staff/{$this->admin->public_id}/activity")
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => ['kind', 'occurred_at', 'actor' => ['public_id']],
            ],
            'meta' => ['current_page', 'per_page', 'total'],
            'links',
        ]);
});

it('returns 403 for a non-admin user', function (): void {
    $regular = User::factory()->create();
    $regular->assignRole('user');

    $this->actingAs($regular)
        ->getJson("/api/admin/staff/{$this->admin->public_id}/activity")
        ->assertForbidden();
});

it('returns 404 for an unknown staff public_id', function (): void {
    $this->actingAs($this->admin)
        ->getJson('/api/admin/staff/01ZZZZZZZZZZZZZZZZZZZZZZZZ/activity')
        ->assertNotFound();
});

it('does not leak sensitive fields in the response body', function (): void {
    // Seed assorted activity
    Settlement::create([
        'driver_id' => $this->driver->id,
        'office_id' => $this->office->id,
        'processed_by_staff_id' => $this->admin->id,
        'cash_received_from_driver' => '20.00',
        'cash_paid_to_driver' => '0.00',
        'status' => SettlementStatus::Completed->value,
    ]);

    $payout = SellerPayout::create([
        'user_id' => $this->driver->id,
        'office_id' => $this->office->id,
        'amount' => '15.00',
        'status' => SellerPayoutStatus::Paid->value,
        'paid_by_staff_id' => $this->admin->id,
        'paid_at' => now(),
    ]);

    $target = User::factory()->create();
    AccountModerationAction::factory()->create([
        'actor_id' => $this->admin->id,
        'user_id' => $target->id,
    ]);

    $strike = DriverStrike::create([
        'driver_id' => $this->driver->id,
        'reason' => DriverStrikeReason::ManualAdmin->value,
        'fee_amount' => '5.00',
        'issued_by' => DriverStrikeIssuer::Admin->value,
        'issued_by_admin_id' => $this->admin->id,
    ]);

    $order = ($this->makeOrder)();
    DB::table('order_status_logs')->insert([
        'order_id' => $order->id,
        'actor_type' => 'admin',
        'actor_id' => $this->admin->id,
        'from_status' => 'created',
        'to_status' => 'assigned',
        'created_at' => now(),
    ]);

    $response = $this->actingAs($this->admin)
        ->getJson("/api/admin/staff/{$this->admin->public_id}/activity")
        ->assertOk();

    $body = $response->getContent();

    // Must NOT contain integer id
    expect($body)->not->toContain('"id":'.$this->admin->id);

    // Must NOT contain pickup_code / delivery_code
    expect($body)->not->toContain('pickup_code');
    expect($body)->not->toContain('delivery_code');

    // actor.public_id MUST be present
    $data = $response->json('data');
    expect($data)->not->toBeEmpty();

    foreach ($data as $item) {
        expect($item)->toHaveKey('actor');
        expect($item['actor'])->toHaveKey('public_id');
    }
});

it('filters by kinds query parameter', function (): void {
    // Seed a settlement and a moderation action
    Settlement::create([
        'driver_id' => $this->driver->id,
        'office_id' => $this->office->id,
        'processed_by_staff_id' => $this->admin->id,
        'cash_received_from_driver' => '10.00',
        'cash_paid_to_driver' => '0.00',
        'status' => SettlementStatus::Completed->value,
    ]);

    $target = User::factory()->create();
    AccountModerationAction::factory()->create([
        'actor_id' => $this->admin->id,
        'user_id' => $target->id,
    ]);

    $response = $this->actingAs($this->admin)
        ->getJson("/api/admin/staff/{$this->admin->public_id}/activity?kinds[]=settlement_processed")
        ->assertOk();

    $kinds = array_unique(array_column($response->json('data'), 'kind'));

    expect($kinds)->toBe(['settlement_processed']);
    expect($response->json('meta.total'))->toBe(1);
});
