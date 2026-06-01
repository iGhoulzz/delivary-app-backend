<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('status log never emits internal *_id keys in metadata or actor', function (): void {
    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $order = Order::factory()->create();
    OrderStatusLog::factory()->create([
        'order_id' => $order->id,
        'actor_id' => $admin->id,
        'metadata' => ['previous_office_public_id' => '01ABC', 'previous_office_id' => 42],
    ]);

    $body = $this->getJson("/api/admin/orders/{$order->public_id}")->json();
    $log = data_get($body, 'data.status_logs.0') ?? data_get($body, 'status_logs.0');

    expect($log)->not->toBeNull();
    expect($log['metadata'] ?? [])->toHaveKey('previous_office_public_id');
    expect($log['metadata'] ?? [])->not->toHaveKey('previous_office_id');
    expect($log)->not->toHaveKey('actor_id');
    expect(data_get($log, 'actor.id'))->toBe($admin->public_id);
    expect(data_get($log, 'actor.name'))->toBe($admin->fullName());
});

it('status log actor is null for system actors', function (): void {
    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $order = Order::factory()->create();
    OrderStatusLog::factory()->create([
        'order_id' => $order->id,
        'actor_type' => App\Enums\OrderActorType::System,
        'actor_id' => null,
        'metadata' => ['event' => 'abandonment_cron'],
    ]);

    $body = $this->getJson("/api/admin/orders/{$order->public_id}")->json();
    $log = data_get($body, 'data.status_logs.0') ?? data_get($body, 'status_logs.0');

    expect($log)->not->toBeNull();
    expect(data_get($log, 'actor'))->toBeNull();
});
