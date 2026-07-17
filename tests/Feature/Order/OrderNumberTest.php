<?php

declare(strict_types=1);

use App\Enums\OrderActorType;
use App\Enums\OrderStatus;
use App\Models\DriverProfile;
use App\Models\DriverStrike;
use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Models\User;
use App\Services\Reporting\OverviewMetricsService;
use App\Support\OrderNumber\OrderNumberBackfiller;
use App\Support\OrderNumber\OrderNumberGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function actingAsOrderAdmin(): User
{
    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    return $admin;
}

it('assigns a valid, unique order_number on creation', function (): void {
    $a = Order::factory()->create();
    $b = Order::factory()->create();

    expect((new OrderNumberGenerator)->isValid($a->order_number))->toBeTrue();
    expect($a->order_number)->not->toBe($b->order_number);
});

it('does not change order_number on update (immutable)', function (): void {
    $order = Order::factory()->create();
    $original = $order->order_number;
    $order->update(['order_number' => 'ORD-AAAA-AAAA-0']); // ignored — not fillable
    expect($order->fresh()->order_number)->toBe($original);
});

it('backfills every row that has no order_number — including soft-deleted orders', function (): void {
    $orders = Order::factory()->count(3)->create();
    $trashed = Order::factory()->create();
    $trashed->delete();                                    // soft-deleted — must still be backfilled

    DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_order_number_unique');
    DB::statement('ALTER TABLE orders ALTER COLUMN order_number DROP NOT NULL');
    DB::table('orders')->update(['order_number' => null]); // DB::table hits the trashed row too
    expect(DB::table('orders')->whereNotNull('order_number')->count())->toBe(0);

    OrderNumberBackfiller::run();                          // the ACTUAL migration backfill code

    $all = DB::table('orders')->pluck('order_number');
    expect($all)->toHaveCount(4);
    expect($all->contains(null))->toBeFalse();
    expect($all->unique()->count())->toBe(4);
    $all->each(fn ($n) => expect((new OrderNumberGenerator)->isValid($n))->toBeTrue());
    expect(DB::table('orders')->where('id', $trashed->id)->value('order_number'))->not->toBeNull();
});

it('enforces NOT NULL on order_number', function (): void {
    $order = Order::factory()->create();
    // Must be the LAST db op — a violation aborts the Postgres transaction.
    expect(fn () => DB::table('orders')->where('id', $order->id)->update(['order_number' => null]))
        ->toThrow(QueryException::class);
});

it('enforces UNIQUE on order_number', function (): void {
    $a = Order::factory()->create();
    $b = Order::factory()->create();
    // Must be the LAST db op.
    expect(fn () => DB::table('orders')->where('id', $b->id)->update(['order_number' => $a->order_number]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('finds an order by order_number, with and without dashes and case', function (): void {
    actingAsOrderAdmin();
    $order = Order::factory()->create();
    $other = Order::factory()->create();          // must NOT be returned
    $number = $order->order_number;               // ORD-XXXX-XXXX-C
    $dashless = str_replace('-', '', $number);    // ORDXXXXXXXXC
    $bodyOnly = substr($dashless, 3, 8);          // XXXXXXXX

    foreach ([$number, strtolower($number), $dashless, $bodyOnly] as $term) {
        $res = $this->getJson('/api/admin/orders?search='.urlencode($term));
        expect($res->status())->toBe(200);
        $ids = collect($res->json('data'))->pluck('id');
        expect($ids)->toContain($order->public_id);
        expect($ids)->not->toContain($other->public_id);
    }
});

it('does not match every order when the term normalizes to empty', function (): void {
    actingAsOrderAdmin();
    Order::factory()->count(3)->create();
    // '---' → normalizeSearchTerm → '' — the order_number clause must be SKIPPED (never LIKE '%%').
    $res = $this->getJson('/api/admin/orders?search='.urlencode('---'));
    expect($res->status())->toBe(200);
    expect($res->json('data'))->toBeEmpty();
});

it('exposes order_number beside id on the admin order resource', function (): void {
    actingAsOrderAdmin();
    $order = Order::factory()->create();
    $res = $this->getJson('/api/admin/orders/'.$order->public_id);
    // AdminOrderResource single responses are wrapped in `data`.
    expect($res->json('data.order_number'))->toBe($order->order_number);
    expect($res->json('data.id'))->toBe($order->public_id); // id unchanged — additive only
});

it('exposes order_number beside id on the nested order reference in DriverStrikeResource', function (): void {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('driver', 'web');

    actingAsOrderAdmin();

    $driver = User::factory()->create();
    $driver->assignRole('driver');
    DriverProfile::factory()->create(['user_id' => $driver->id]);

    $order = Order::factory()->create();
    DriverStrike::create([
        'driver_id' => $driver->id,
        'order_id' => $order->id,
        'reason' => 'no_show_at_pickup',
        'issued_by' => 'system',
        'fee_amount' => 10,
        'is_voided' => false,
    ]);

    $res = $this->getJson("/api/admin/drivers/{$driver->public_id}/strikes");

    expect($res->status())->toBe(200);
    expect($res->json('strikes.0.order.id'))->toBe($order->public_id);
    expect($res->json('strikes.0.order.order_number'))->toBe($order->order_number);
});

it('carries order_number beside order_public_id in the overview activity feed', function (): void {
    $actor = User::factory()->create();
    $order = Order::factory()->create(['status' => OrderStatus::Delivered->value]);

    OrderStatusLog::query()->create([
        'order_id' => $order->id,
        'from_status' => OrderStatus::DeliveryInProgress->value,
        'to_status' => OrderStatus::Delivered->value,
        'actor_type' => OrderActorType::Admin->value,
        'actor_id' => $actor->id,
        'created_at' => now(),
    ]);

    $activity = app(OverviewMetricsService::class)->build()['activity'];

    expect($activity)->toHaveCount(1)
        ->and($activity[0]['order_public_id'])->toBe($order->public_id)
        ->and($activity[0]['order_number'])->toBe($order->order_number);
});
