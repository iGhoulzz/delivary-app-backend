<?php

declare(strict_types=1);

use App\Enums\DriverActivityStatus;
use App\Enums\OrderActorType;
use App\Enums\OrderStatus;
use App\Models\DriverAccount;
use App\Models\DriverProfile;
use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Models\User;
use App\Services\Reporting\OverviewMetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
    config()->set('reporting.timezone', 'Africa/Tripoli');
});

function actingAsOverviewAdmin(): User
{
    $admin = User::factory()->create(['must_change_password' => false]);
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    return $admin;
}

it('counts active orders, online drivers, and pending settlements from current state', function (): void {
    Order::factory()->create(['status' => OrderStatus::AtOffice->value]);
    Order::factory()->create(['status' => OrderStatus::Delivered->value]);
    Order::factory()->create(['status' => OrderStatus::CancelledByUser->value]);

    DriverAccount::factory()->create([
        'cash_to_deposit' => '0.00',
        'earnings_balance' => '5.00',
        'debt_balance' => '0.00',
    ]);
    DriverAccount::factory()->create([
        'cash_to_deposit' => '0.00',
        'earnings_balance' => '0.00',
        'debt_balance' => '0.00',
    ]);

    DriverProfile::factory()->create(['activity_status' => DriverActivityStatus::Online->value]);
    DriverProfile::factory()->create(['activity_status' => DriverActivityStatus::OnOrder->value]);
    DriverProfile::factory()->create(['activity_status' => DriverActivityStatus::Offline->value]);

    $metrics = app(OverviewMetricsService::class)->build();
    $stats = collect($metrics['stats'])->keyBy('id');

    expect($stats['active_orders']['value'])->toBe(1)
        ->and($stats['active_orders']['delta_pct'])->toBeNull()
        ->and($stats['active_orders']['sparkline'])->toBeNull()
        ->and($stats['online_drivers']['value'])->toBe(2)
        ->and($stats['pending_settlements']['value'])->toBe(1);
});

it('counts delivered today by delivery time in the reporting timezone', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'Africa/Tripoli'));

    try {
        Order::factory()->create([
            'status' => OrderStatus::Delivered->value,
            'created_at' => CarbonImmutable::parse('2026-06-21 10:00:00', 'Africa/Tripoli')->utc(),
            'delivered_at' => CarbonImmutable::parse('2026-06-22 11:00:00', 'Africa/Tripoli')->utc(),
        ]);
        Order::factory()->create([
            'status' => OrderStatus::Delivered->value,
            'created_at' => CarbonImmutable::parse('2026-06-21 21:00:00', 'UTC'),
            'delivered_at' => CarbonImmutable::parse('2026-06-21 22:30:00', 'UTC'),
        ]);
        Order::factory()->create([
            'status' => OrderStatus::Delivered->value,
            'created_at' => CarbonImmutable::parse('2026-06-22 08:00:00', 'Africa/Tripoli')->utc(),
            'delivered_at' => CarbonImmutable::parse('2026-06-21 08:00:00', 'Africa/Tripoli')->utc(),
        ]);

        $metrics = app(OverviewMetricsService::class)->build();
        $card = collect($metrics['stats'])->firstWhere('id', 'delivered_today');

        expect($card['value'])->toBe(2)
            ->and($card['sparkline'])->toHaveCount(7)
            ->and($card['delta_pct'])->not->toBeNull()
            ->and($card['direction'])->toBe('up');
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('returns recent order activity with public actor references', function (): void {
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
        ->and($activity[0]['kind'])->toBe('delivered')
        ->and($activity[0]['order_public_id'])->toBe($order->public_id)
        ->and($activity[0]['actor'])->toBe([
            'public_id' => $actor->public_id,
            'name' => $actor->fullName(),
        ])
        ->and($activity[0])->not->toHaveKey('actor_id');
});

it('returns overview for admins and forbids non-admins', function (): void {
    actingAsOverviewAdmin();

    $this->getJson('/api/admin/overview')
        ->assertOk()
        ->assertJsonStructure([
            'stats' => [['id', 'value', 'money', 'delta_pct', 'direction', 'sparkline']],
            'activity',
        ]);

    Sanctum::actingAs(User::factory()->create(['must_change_password' => false]));

    $this->getJson('/api/admin/overview')->assertForbidden();
});
