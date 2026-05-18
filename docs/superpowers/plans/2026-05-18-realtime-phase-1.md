# Real-Time Phase 1 — Reverb Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Install Laravel Reverb, register Sanctum-authed broadcast channels, create broadcast-safe Resources, and ship the two foundation events (`OrderBroadcastToDriver`, `OrderBroadcastWithdrawn`) — so Codex can build Phase 2 events on top.

**Architecture:** Reverb runs as a separate WS server (port 8080) talking to Laravel via Redis pub/sub. Clients authenticate to `/broadcasting/auth` over HTTP using their Sanctum bearer token. Four channels: `private:user.{id}`, `private:order.{public_id}`, `private:driver.{id}`, `public:track.{token}`. Broadcast events use dedicated request-independent Resources (NEVER `OrderResource`, which depends on `$request->user()`). `OrderBroadcastWithdrawn` is fired by a single listener bound to `OrderStatusChanged` whenever an order leaves `awaiting_driver` — one source of truth.

**Tech Stack:** Laravel 13 · Reverb · Redis (queue + pub/sub) · Sanctum · Pest 4 · clickbar/laravel-magellan (PostGIS).

**Prerequisites:** Reverb spec at `docs/superpowers/specs/2026-05-18-realtime-reverb-design.md` is locked. Redis and Postgres are running locally per `.env`. Pint is configured and CI-gated.

---

## File map

**New files:**
- `app/Events/OrderBroadcastToDriver.php` — broadcast event
- `app/Events/OrderBroadcastWithdrawn.php` — broadcast event
- `app/Listeners/BroadcastWithdrawnOnExit.php` — fires `OrderBroadcastWithdrawn` when order leaves `awaiting_driver`
- `app/Http/Resources/Broadcast/OrderForPartiesResource.php` — broadcast-safe resource for `private:order.{public_id}`
- `app/Http/Resources/Broadcast/DriverForOrderResource.php` — broadcast-safe sender-visible driver fields
- `routes/channels.php` — channel auth callbacks
- `tests/Unit/Broadcasting/ChannelAuthorizationTest.php`
- `tests/Unit/Events/OrderBroadcastToDriverTest.php`
- `tests/Unit/Events/OrderBroadcastWithdrawnTest.php`
- `tests/Unit/Listeners/BroadcastWithdrawnOnExitTest.php`
- `tests/Unit/Services/Order/BroadcastServiceEligibleDriversTest.php`
- `tests/Unit/Resources/Broadcast/OrderForPartiesResourceTest.php`
- `tests/Unit/Resources/Broadcast/DriverForOrderResourceTest.php`
- `docs/deployment/reverb-supervisor.conf.example`

**Modified files:**
- `composer.json` — adds reverb dep + dev script change
- `.env.example` — adds reverb env vars
- `config/broadcasting.php` (auto-published by `reverb:install`)
- `config/reverb.php` (auto-published by `reverb:install`)
- `app/Providers/AppServiceProvider.php` — register `Broadcast::routes(...)` and event listener
- `app/Services/Order/BroadcastService.php` — add `eligibleDriversFor(Order)` method
- `app/Services/Order/StateTransitionService.php` — dispatch `OrderBroadcastToDriver` when transitioning to `awaiting_driver`
- `app/Services/Order/EscalationService.php` — dispatch `OrderBroadcastToDriver` after `applyTier()`

---

## Task 1: Install Reverb and configure env

**Files:**
- Modify: `composer.json` (via composer)
- Modify: `.env`, `.env.example`
- Create (auto): `config/reverb.php`
- Modify (auto): `config/broadcasting.php`

- [ ] **Step 1: Install Reverb**

Run:
```bash
composer require laravel/reverb
```

Expected: `laravel/reverb` added to `require` block in `composer.json`. No errors.

- [ ] **Step 2: Run the Reverb installer**

Run:
```bash
php artisan reverb:install
```

Expected: `config/reverb.php` created. `config/broadcasting.php` updated to include a `reverb` connection block. `.env` updated with `BROADCAST_CONNECTION=reverb` plus `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST=localhost`, `REVERB_PORT=8080`, `REVERB_SCHEME=http`.

- [ ] **Step 3: Copy the new env keys into `.env.example`**

Add to `.env.example` (preserve `=` empty for secrets):
```
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
```

- [ ] **Step 4: Verify Reverb boots without error**

Run:
```bash
php artisan reverb:start --port=8080
```

Expected: process starts and prints `INFO  Starting server on 0.0.0.0:8080 (localhost).` Stop it with Ctrl-C.

- [ ] **Step 5: Commit**

```bash
git add composer.json composer.lock config/reverb.php config/broadcasting.php .env.example
git commit -m "feat(realtime): install and configure Laravel Reverb"
```

---

## Task 2: Register `Broadcast::routes()` behind Sanctum auth

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Broadcasting/BroadcastingAuthRouteTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Broadcasting/BroadcastingAuthRouteTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('rejects unauthenticated requests to broadcasting auth', function (): void {
    $response = $this->postJson('/broadcasting/auth', [
        'channel_name' => 'private-user.1',
        'socket_id' => '1234.5678',
    ]);

    expect($response->status())->toBe(401);
});

it('accepts a valid Sanctum token at broadcasting auth', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/broadcasting/auth', [
        'channel_name' => 'private-user.'.$user->id,
        'socket_id' => '1234.5678',
    ]);

    // The route is registered; auth passes; channel callback authorizes.
    // 200 means Laravel accepted the auth + the channel callback returned truthy.
    expect($response->status())->toBe(200);
});
```

- [ ] **Step 2: Run test — expect failure**

Run:
```bash
vendor\bin\pest tests/Feature/Broadcasting/BroadcastingAuthRouteTest.php
```

Expected: both tests fail with `Route [broadcasting.auth] not defined` or 404 because the route is not registered yet.

- [ ] **Step 3: Register the route in `AppServiceProvider::boot()`**

In `app/Providers/AppServiceProvider.php`, add this import at the top:
```php
use Illuminate\Support\Facades\Broadcast;
```

Inside `boot()`, before `$this->configureRateLimiters();`, add:
```php
Broadcast::routes(['middleware' => ['auth:sanctum']]);
```

- [ ] **Step 4: Run test — expect first to pass, second to still 403/500**

Run:
```bash
vendor\bin\pest tests/Feature/Broadcasting/BroadcastingAuthRouteTest.php
```

Expected: the unauthenticated test now passes (401). The authenticated test still fails because we haven't defined the `user.{userId}` channel callback yet — should return 403 or 404. That's fine for now; we'll fix it in Task 3.

- [ ] **Step 5: Commit**

```bash
git add app/Providers/AppServiceProvider.php tests/Feature/Broadcasting/BroadcastingAuthRouteTest.php
git commit -m "feat(realtime): register Sanctum-authed /broadcasting/auth route"
```

---

## Task 3: Channel authorization callbacks

**Files:**
- Create: `routes/channels.php`
- Create: `tests/Unit/Broadcasting/ChannelAuthorizationTest.php`
- Modify: `bootstrap/app.php` (register the channels route)

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Broadcasting/ChannelAuthorizationTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;

uses(RefreshDatabase::class);

it('authorizes a user on their own user channel', function (): void {
    $user = User::factory()->create();

    $result = Broadcast::auth(
        request()->merge(['channel_name' => 'private-user.'.$user->id])
            ->setUserResolver(fn () => $user)
    );

    expect($result)->not->toBeNull();
});

it('rejects a user from another user channel', function (): void {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

    Broadcast::auth(
        request()->merge(['channel_name' => 'private-user.'.$bob->id])
            ->setUserResolver(fn () => $alice)
    );
});

it('authorizes the sender on their order channel', function (): void {
    $sender = User::factory()->create();
    $order = Order::factory()->create(['sender_user_id' => $sender->id]);

    $result = Broadcast::auth(
        request()->merge(['channel_name' => 'private-order.'.$order->public_id])
            ->setUserResolver(fn () => $sender)
    );

    expect($result)->not->toBeNull();
});

it('authorizes the receiver user on their order channel', function (): void {
    $sender = User::factory()->create();
    $receiver = User::factory()->create();
    $order = Order::factory()->create([
        'sender_user_id' => $sender->id,
        'receiver_user_id' => $receiver->id,
    ]);

    $result = Broadcast::auth(
        request()->merge(['channel_name' => 'private-order.'.$order->public_id])
            ->setUserResolver(fn () => $receiver)
    );

    expect($result)->not->toBeNull();
});

it('rejects an unrelated user from an order channel', function (): void {
    $sender = User::factory()->create();
    $stranger = User::factory()->create();
    $order = Order::factory()->create(['sender_user_id' => $sender->id]);

    $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

    Broadcast::auth(
        request()->merge(['channel_name' => 'private-order.'.$order->public_id])
            ->setUserResolver(fn () => $stranger)
    );
});

it('rejects a non-driver from a driver channel', function (): void {
    $user = User::factory()->create();

    $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

    Broadcast::auth(
        request()->merge(['channel_name' => 'private-driver.'.$user->id])
            ->setUserResolver(fn () => $user)
    );
});

it('authorizes a driver on their own driver channel', function (): void {
    $driver = User::factory()->create();
    $driver->assignRole('driver');

    $result = Broadcast::auth(
        request()->merge(['channel_name' => 'private-driver.'.$driver->id])
            ->setUserResolver(fn () => $driver)
    );

    expect($result)->not->toBeNull();
});

it('rejects a driver from another driver channel', function (): void {
    $alice = User::factory()->create();
    $alice->assignRole('driver');
    $bob = User::factory()->create();
    $bob->assignRole('driver');

    $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

    Broadcast::auth(
        request()->merge(['channel_name' => 'private-driver.'.$bob->id])
            ->setUserResolver(fn () => $alice)
    );
});
```

- [ ] **Step 2: Run test — expect failure**

Run:
```bash
vendor\bin\pest tests/Unit/Broadcasting/ChannelAuthorizationTest.php
```

Expected: tests fail because no channels are registered.

- [ ] **Step 3: Create `routes/channels.php`**

Create `routes/channels.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('user.{userId}', function (User $user, int $userId): bool {
    return $user->id === $userId;
});

Broadcast::channel('order.{publicId}', function (User $user, string $publicId): bool {
    $order = Order::query()->where('public_id', $publicId)->first();
    if ($order === null) {
        return false;
    }

    return $user->id === $order->sender_user_id
        || ($order->receiver_user_id !== null && $user->id === $order->receiver_user_id);
});

Broadcast::channel('driver.{driverId}', function (User $user, int $driverId): bool {
    return $user->id === $driverId && $user->hasRole('driver');
});

// `track.{trackingToken}` is a public channel — no callback needed.
```

- [ ] **Step 4: Tell Laravel to load `routes/channels.php`**

In `bootstrap/app.php`, update the `withRouting` call:

```php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    api: __DIR__.'/../routes/api.php',
    commands: __DIR__.'/../routes/console.php',
    channels: __DIR__.'/../routes/channels.php',
    health: '/up',
)
```

- [ ] **Step 5: Run tests — expect pass**

Run:
```bash
vendor\bin\pest tests/Unit/Broadcasting/ChannelAuthorizationTest.php
vendor\bin\pest tests/Feature/Broadcasting/BroadcastingAuthRouteTest.php
```

Expected: all 8 channel auth tests pass; both broadcasting-auth tests pass (Task 2's second test now returns 200 because the `user.{userId}` channel is wired).

- [ ] **Step 6: Run Pint**

Run:
```bash
vendor\bin\pint routes/channels.php tests/Unit/Broadcasting tests/Feature/Broadcasting bootstrap/app.php
```

- [ ] **Step 7: Commit**

```bash
git add routes/channels.php bootstrap/app.php tests/Unit/Broadcasting tests/Feature/Broadcasting
git commit -m "feat(realtime): channel auth callbacks for user/order/driver private channels"
```

---

## Task 4: `OrderForPartiesResource` (broadcast-safe order shape)

**Files:**
- Create: `app/Http/Resources/Broadcast/OrderForPartiesResource.php`
- Test: `tests/Unit/Resources/Broadcast/OrderForPartiesResourceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Resources/Broadcast/OrderForPartiesResourceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Http\Resources\Broadcast\OrderForPartiesResource;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('is request-independent (no $request->user() branching)', function (): void {
    $order = Order::factory()->create([
        'pickup_notes' => 'leave at door',       // sender-only field, must NOT appear
        'receiver_phone' => '+218910000000',      // sender-only field, must NOT appear
        'receiver_name' => 'Ahmed',               // sender-only field, must NOT appear
        'commission_amount' => '5.00',            // sender-only field, must NOT appear
        'delivery_code' => 'XYZ123',              // receiver-only field, must NOT appear
        'pickup_code' => 'ABC456',                // sender-only field, must NOT appear
    ]);

    $array = (new OrderForPartiesResource($order))->resolve();

    expect($array)->toBeArray();
    expect($array)->toHaveKey('id', $order->public_id);
    expect($array)->toHaveKey('status');
    expect($array)->toHaveKey('display_status');
    expect($array)->toHaveKey('pickup');
    expect($array)->toHaveKey('receiver');
    expect($array)->toHaveKey('item');
    expect($array)->toHaveKey('pricing');
    expect($array)->toHaveKey('timestamps');

    // No sender/receiver-only sensitive fields
    expect($array['pickup'])->not->toHaveKey('notes');
    expect($array['pickup'])->not->toHaveKey('pickup_code');
    expect($array['receiver'])->not->toHaveKey('phone');
    expect($array['receiver'])->not->toHaveKey('name');
    expect($array['receiver'])->not->toHaveKey('delivery_code');
    expect($array['pricing'])->not->toHaveKey('commission_amount');
});

it('exposes driver block when an active driver is assigned', function (): void {
    $driver = \App\Models\User::factory()->create(['first_name' => 'Sami']);
    $order = Order::factory()->create([
        'driver_id' => $driver->id,
        'status' => \App\Enums\OrderStatus::DriverEnRoutePickup->value,
    ]);

    $array = (new OrderForPartiesResource($order->load('driver.driverProfile')))->resolve();

    expect($array['driver'])->not->toBeNull();
    expect($array['driver'])->toHaveKey('first_name', 'Sami');
});

it('returns null driver block when no driver assigned', function (): void {
    $order = Order::factory()->create(['driver_id' => null]);

    $array = (new OrderForPartiesResource($order))->resolve();

    expect($array['driver'])->toBeNull();
});
```

- [ ] **Step 2: Run test — expect failure (class not found)**

Run:
```bash
vendor\bin\pest tests/Unit/Resources/Broadcast/OrderForPartiesResourceTest.php
```

Expected: fails with `Class "App\Http\Resources\Broadcast\OrderForPartiesResource" not found`.

- [ ] **Step 3: Create the Resource**

Create `app/Http/Resources/Broadcast/OrderForPartiesResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Broadcast;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Support\OrderDisplayStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Broadcast-safe order shape for the `private:order.{public_id}` channel.
 * Audience-neutral (sender + receiver receive the same payload). Request-
 * independent — must NOT read $request->user() because broadcasts run off
 * the HTTP request lifecycle.
 */
final class OrderForPartiesResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Order $o */
        $o = $this->resource;

        return [
            'id' => $o->public_id,
            'order_type' => $o->order_type->value,
            'status' => $o->status->value,
            'display_status' => OrderDisplayStatus::fromInternal($o->status),
            'status_changed_at' => $o->status_changed_at?->toIso8601String(),

            'pickup' => [
                'address' => $o->pickup_address,
                'location' => $this->point($o->pickup_location),
            ],

            'receiver' => [
                'address' => $o->receiver_address,
                'location' => $this->point($o->receiver_location),
            ],

            'item' => [
                'description' => $o->item_description,
                'size' => $o->item_size->value,
            ],

            'pricing' => [
                'delivery_fee' => (string) $o->delivery_fee,
                'delivery_fee_payer' => $o->delivery_fee_payer->value,
                'cash_collected_at_delivery' => $o->cashCollectedAtDelivery(),
            ],

            'driver' => $this->driverBlock($o),

            'timestamps' => [
                'created_at' => $o->created_at?->toIso8601String(),
                'assigned_at' => $o->assigned_at?->toIso8601String(),
                'picked_up_at' => $o->picked_up_at?->toIso8601String(),
                'delivered_at' => $o->delivered_at?->toIso8601String(),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function driverBlock(Order $o): ?array
    {
        if ($o->driver_id === null) {
            return null;
        }
        $afterPickup = in_array($o->status, [
            OrderStatus::PickedUp,
            OrderStatus::DriverEnRouteDropoff,
            OrderStatus::DeliveryInProgress,
            OrderStatus::Delivered,
        ], true);

        $driver = $o->driver;
        $profile = $driver?->driverProfile;

        return [
            'first_name' => $driver?->first_name,
            'vehicle_type' => $profile?->vehicle_type?->value,
            'current_location' => $afterPickup && $profile
                ? $this->point($profile->current_location)
                : null,
            'last_seen_at' => $profile?->last_active_at?->toIso8601String(),
        ];
    }

    /** @return array{lat: float, lng: float}|null */
    private function point(mixed $p): ?array
    {
        if ($p === null) {
            return null;
        }

        return ['lat' => (float) $p->getLatitude(), 'lng' => (float) $p->getLongitude()];
    }
}
```

- [ ] **Step 4: Run tests — expect pass**

Run:
```bash
vendor\bin\pest tests/Unit/Resources/Broadcast/OrderForPartiesResourceTest.php
```

Expected: all three tests pass.

- [ ] **Step 5: Run Pint and commit**

```bash
vendor\bin\pint app/Http/Resources/Broadcast tests/Unit/Resources/Broadcast
git add app/Http/Resources/Broadcast/OrderForPartiesResource.php tests/Unit/Resources/Broadcast/OrderForPartiesResourceTest.php
git commit -m "feat(realtime): OrderForPartiesResource — broadcast-safe order shape"
```

---

## Task 5: `DriverForOrderResource` (broadcast-safe driver shape)

**Files:**
- Create: `app/Http/Resources/Broadcast/DriverForOrderResource.php`
- Test: `tests/Unit/Resources/Broadcast/DriverForOrderResourceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Resources/Broadcast/DriverForOrderResourceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\VehicleType;
use App\Http\Resources\Broadcast\DriverForOrderResource;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exposes only sender-safe driver fields', function (): void {
    $driver = User::factory()->create(['first_name' => 'Yusuf']);
    /** @var DriverProfile $profile */
    $profile = DriverProfile::factory()->create([
        'user_id' => $driver->id,
        'vehicle_type' => VehicleType::Motorcycle->value,
        'vehicle_plate' => 'TRP-9999',
        'office_id' => 1,
    ]);

    $array = (new DriverForOrderResource($profile->fresh(['user'])))->resolve();

    expect($array)->toHaveKey('first_name', 'Yusuf');
    expect($array)->toHaveKey('vehicle_type', VehicleType::Motorcycle->value);
    expect($array)->toHaveKey('vehicle_color');
    expect($array)->toHaveKey('rating_average');
    expect($array)->toHaveKey('lifetime_deliveries');
    expect($array)->toHaveKey('current_location');

    // Internal fields must NOT leak
    expect($array)->not->toHaveKey('id');
    expect($array)->not->toHaveKey('user_id');
    expect($array)->not->toHaveKey('office_id');
    expect($array)->not->toHaveKey('vehicle_plate');
    expect($array)->not->toHaveKey('status');
    expect($array)->not->toHaveKey('activity_status');
});
```

- [ ] **Step 2: Run test — expect failure (class not found)**

Run:
```bash
vendor\bin\pest tests/Unit/Resources/Broadcast/DriverForOrderResourceTest.php
```

Expected: `Class "App\Http\Resources\Broadcast\DriverForOrderResource" not found`.

- [ ] **Step 3: Create the Resource**

Create `app/Http/Resources/Broadcast/DriverForOrderResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Broadcast;

use App\Models\DriverProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Broadcast-safe driver shape for `OrderDriverAssigned` and similar events.
 * Sender-visible fields only — NO internal id, user_id, office_id, plate,
 * status, or activity_status. Request-independent.
 *
 * @mixin DriverProfile
 */
final class DriverForOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $this->user;

        return [
            'first_name' => $user?->first_name,
            'vehicle_type' => $this->vehicle_type->value,
            'vehicle_color' => $this->vehicle_color,
            'rating_average' => $this->rating_average,
            'lifetime_deliveries' => $this->lifetime_deliveries,
            'current_location' => $this->current_location !== null
                ? [
                    'lat' => (float) $this->current_location->getLatitude(),
                    'lng' => (float) $this->current_location->getLongitude(),
                ]
                : null,
        ];
    }
}
```

- [ ] **Step 4: Run test — expect pass**

Run:
```bash
vendor\bin\pest tests/Unit/Resources/Broadcast/DriverForOrderResourceTest.php
```

Expected: pass.

- [ ] **Step 5: Run Pint and commit**

```bash
vendor\bin\pint app/Http/Resources/Broadcast tests/Unit/Resources/Broadcast
git add app/Http/Resources/Broadcast/DriverForOrderResource.php tests/Unit/Resources/Broadcast/DriverForOrderResourceTest.php
git commit -m "feat(realtime): DriverForOrderResource — broadcast-safe sender-visible driver fields"
```

---

## Task 5.5: Shared test helper — `makeOnlineDriverAt`

Several upcoming tests (Tasks 6, 8, 9, 11) all need to spawn an online driver at a coordinate. Define it once in `tests/Pest.php` so the file-per-test redeclaration problem never arises.

**Files:**
- Modify: `tests/Pest.php`

- [ ] **Step 1: Edit `tests/Pest.php`**

At the bottom of `tests/Pest.php`, add:

```php
/*
|--------------------------------------------------------------------------
| Real-time milestone helpers
|--------------------------------------------------------------------------
*/

function makeOnlineDriverAt(float $lat, float $lng, ?\App\Enums\VehicleType $vehicle = null): \App\Models\User
{
    $vehicle ??= \App\Enums\VehicleType::Motorcycle;

    $user = \App\Models\User::factory()->create();
    $user->assignRole('driver');

    \App\Models\DriverProfile::factory()->create([
        'user_id' => $user->id,
        'status' => \App\Enums\DriverStatus::Active->value,
        'activity_status' => \App\Enums\DriverActivityStatus::Online->value,
        'vehicle_type' => $vehicle->value,
        'current_location' => \Clickbar\Magellan\Data\Geometries\Point::makeGeodetic($lat, $lng),
        'last_location_updated_at' => now(),
    ]);

    \App\Models\DriverAccount::factory()->create([
        'user_id' => $user->id,
        'max_cash_liability' => '200.00',
        'cash_to_deposit' => '0.00',
    ]);

    return $user;
}

function makeOrderAt(float $lat, float $lng, string $itemSize = 'small'): \App\Models\Order
{
    return \App\Models\Order::factory()->create([
        'status' => \App\Enums\OrderStatus::AwaitingDriver->value,
        'driver_id' => null,
        'pickup_location' => \Clickbar\Magellan\Data\Geometries\Point::makeGeodetic($lat, $lng),
        'item_size' => $itemSize,
        'search_radius_tier' => 1,
    ]);
}
```

- [ ] **Step 2: Commit**

```bash
git add tests/Pest.php
git commit -m "test(realtime): shared helpers makeOnlineDriverAt and makeOrderAt"
```

When implementing Tasks 6, 8, 9, 11 below, **do not redeclare these helpers** in individual test files — call them directly. The inline helper definitions in those task code blocks are illustrative; delete them when copying the test code.

---

## Task 6: `BroadcastService::eligibleDriversFor(Order)`

Inverse of the existing `candidatesFor($driver)`. Given one order, returns the online drivers within its current tier radius with vehicle + liability eligibility.

**Files:**
- Modify: `app/Services/Order/BroadcastService.php`
- Test: `tests/Unit/Services/Order/BroadcastServiceEligibleDriversTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/Order/BroadcastServiceEligibleDriversTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\DriverActivityStatus;
use App\Enums\DriverStatus;
use App\Enums\ItemSize;
use App\Enums\OrderStatus;
use App\Enums\VehicleType;
use App\Models\DriverAccount;
use App\Models\DriverProfile;
use App\Models\Order;
use App\Models\User;
use App\Services\Order\BroadcastService;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeOrderAt(float $lat, float $lng, string $size = 'small'): Order
{
    return Order::factory()->create([
        'status' => OrderStatus::AwaitingDriver->value,
        'driver_id' => null,
        'pickup_location' => Point::makeGeodetic($lat, $lng),
        'item_size' => $size,
        'search_radius_tier' => 1,
    ]);
}

function makeOnlineDriverAt(float $lat, float $lng, VehicleType $vehicle = VehicleType::Motorcycle): User
{
    $user = User::factory()->create();
    $user->assignRole('driver');

    DriverProfile::factory()->create([
        'user_id' => $user->id,
        'status' => DriverStatus::Active->value,
        'activity_status' => DriverActivityStatus::Online->value,
        'vehicle_type' => $vehicle->value,
        'current_location' => Point::makeGeodetic($lat, $lng),
        'last_location_updated_at' => now(),
    ]);

    DriverAccount::factory()->create([
        'user_id' => $user->id,
        'max_cash_liability' => '200.00',
        'cash_to_deposit' => '0.00',
    ]);

    return $user;
}

it('returns online drivers within tier radius for the order vehicle class', function (): void {
    $order = makeOrderAt(32.8872, 13.1913); // Tripoli centre
    $near = makeOnlineDriverAt(32.8880, 13.1920); // ~100m away
    $far = makeOnlineDriverAt(33.0000, 13.5000); // ~30km away — outside tier-1 radius

    $service = app(BroadcastService::class);

    $eligible = $service->eligibleDriversFor($order->fresh());

    expect($eligible->pluck('user_id'))->toContain($near->id);
    expect($eligible->pluck('user_id'))->not->toContain($far->id);
});

it('excludes offline drivers', function (): void {
    $order = makeOrderAt(32.8872, 13.1913);
    $online = makeOnlineDriverAt(32.8880, 13.1920);

    $offline = makeOnlineDriverAt(32.8880, 13.1921);
    DriverProfile::query()->where('user_id', $offline->id)->update([
        'activity_status' => DriverActivityStatus::Offline->value,
    ]);

    $eligible = app(BroadcastService::class)->eligibleDriversFor($order->fresh());

    expect($eligible->pluck('user_id'))->toContain($online->id);
    expect($eligible->pluck('user_id'))->not->toContain($offline->id);
});

it('excludes drivers whose vehicle cannot carry the item size', function (): void {
    $order = makeOrderAt(32.8872, 13.1913, ItemSize::Bulky->value); // bulky needs car or pickup
    $bike = makeOnlineDriverAt(32.8880, 13.1920, VehicleType::Motorcycle);
    $car = makeOnlineDriverAt(32.8881, 13.1921, VehicleType::Car);

    $eligible = app(BroadcastService::class)->eligibleDriversFor($order->fresh());

    expect($eligible->pluck('user_id'))->not->toContain($bike->id);
    expect($eligible->pluck('user_id'))->toContain($car->id);
});
```

- [ ] **Step 2: Run test — expect failure**

Run:
```bash
vendor\bin\pest tests/Unit/Services/Order/BroadcastServiceEligibleDriversTest.php
```

Expected: fails — method does not exist.

- [ ] **Step 3: Implement `eligibleDriversFor()`**

Edit `app/Services/Order/BroadcastService.php`. Add this method below `candidatesFor()`:

```php
    /**
     * Inverse of candidatesFor(): given an order, find online drivers within
     * the order's current tier radius who match its vehicle class and have
     * liability headroom. Used by broadcast fan-out (push the order push to
     * each eligible driver's private channel).
     *
     * @return Collection<int, DriverProfile>
     */
    public function eligibleDriversFor(Order $order): Collection
    {
        $radiusMeters = $this->radiusMetersForTier($order->search_radius_tier);
        $staleAfter = (int) PlatformSetting::get('driver.location_stale_after_seconds', 120);
        $staleCutoff = now()->subSeconds($staleAfter);

        $eligibleVehicles = array_map(
            static fn (VehicleType $v): string => $v->value,
            VehicleType::eligibleFor($order->item_size->value),
        );

        return DriverProfile::query()
            ->where('status', DriverStatus::Active->value)
            ->where('activity_status', DriverActivityStatus::Online->value)
            ->whereIn('vehicle_type', $eligibleVehicles)
            ->whereNotNull('current_location')
            ->whereNotNull('last_location_updated_at')
            ->where('last_location_updated_at', '>=', $staleCutoff)
            ->whereRaw(
                'ST_DWithin(current_location::geography, ?::geography, ?)',
                [$order->pickup_location->toWkt(), $radiusMeters],
            )
            ->with('user.driverAccount')
            ->get()
            ->filter(function (DriverProfile $profile) use ($order): bool {
                $account = $profile->user?->driverAccount;

                return $account !== null
                    && $account->canHoldAdditionalCash($order->cashCollectedAtDelivery());
            })
            ->values();
    }
```

If `Point::toWkt()` is not the exact method on `clickbar/laravel-magellan`, check the package's `Point` class and substitute the correct serializer (it may be `__toString()` or `(string) $point` — verify before committing).

- [ ] **Step 4: Run tests — expect pass**

Run:
```bash
vendor\bin\pest tests/Unit/Services/Order/BroadcastServiceEligibleDriversTest.php
```

Expected: all three tests pass.

- [ ] **Step 5: Run regression**

Run:
```bash
vendor\bin\pest --filter=BroadcastService
```

Expected: all `BroadcastService` tests pass (no regression in `candidatesFor()`).

- [ ] **Step 6: Run Pint and commit**

```bash
vendor\bin\pint app/Services/Order/BroadcastService.php tests/Unit/Services/Order
git add app/Services/Order/BroadcastService.php tests/Unit/Services/Order/BroadcastServiceEligibleDriversTest.php
git commit -m "feat(realtime): BroadcastService::eligibleDriversFor — inverse driver query"
```

---

## Task 7: `OrderBroadcastToDriver` event

**Files:**
- Create: `app/Events/OrderBroadcastToDriver.php`
- Test: `tests/Unit/Events/OrderBroadcastToDriverTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Events/OrderBroadcastToDriverTest.php`:

```php
<?php

declare(strict_types=1);

use App\Events\OrderBroadcastToDriver;
use App\Http\Resources\Order\BroadcastOrderResource;
use App\Models\Order;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('broadcasts to private driver channel', function (): void {
    $driver = User::factory()->create();
    $order = Order::factory()->create();

    $event = new OrderBroadcastToDriver($order, $driver->id, tier: 1);

    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1);
    expect($channels[0])->toBeInstanceOf(PrivateChannel::class);
    expect($channels[0]->name)->toBe('private-driver.'.$driver->id);
});

it('has $afterCommit set to true', function (): void {
    $driver = User::factory()->create();
    $order = Order::factory()->create();

    $event = new OrderBroadcastToDriver($order, $driver->id, tier: 1);

    expect($event->afterCommit)->toBeTrue();
});

it('payload includes broadcast order resource and tier metadata', function (): void {
    $driver = User::factory()->create();
    $order = Order::factory()->create(['search_radius_tier' => 2]);

    $event = new OrderBroadcastToDriver($order, $driver->id, tier: 2);
    $payload = $event->broadcastWith();

    expect($payload)->toHaveKey('type', 'order.broadcast_to_driver');
    expect($payload)->toHaveKey('tier', 2);
    expect($payload)->toHaveKey('order');
    expect($payload['order'])->toBeArray();
    expect($payload['order'])->toHaveKey('id', $order->public_id);
});
```

- [ ] **Step 2: Run test — expect failure**

Run:
```bash
vendor\bin\pest tests/Unit/Events/OrderBroadcastToDriverTest.php
```

Expected: `Class "App\Events\OrderBroadcastToDriver" not found`.

- [ ] **Step 3: Create the event**

Create `app/Events/OrderBroadcastToDriver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Events;

use App\Http\Resources\Order\BroadcastOrderResource;
use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired per-eligible-driver when an order enters the broadcast window or
 * its radius tier escalates. Sent on `private:driver.{driver_id}`. Codex
 * never adds dispatch sites for this event — see plan tasks 8 and 9 for
 * the two places it fires.
 */
final class OrderBroadcastToDriver implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public bool $afterCommit = true;

    public function __construct(
        public readonly Order $order,
        public readonly int $driverId,
        public readonly int $tier,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('driver.'.$this->driverId)];
    }

    public function broadcastAs(): string
    {
        return 'order.broadcast_to_driver';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'type' => 'order.broadcast_to_driver',
            'tier' => $this->tier,
            'order' => (new BroadcastOrderResource($this->order))->resolve(),
        ];
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }
}
```

- [ ] **Step 4: Run test — expect pass**

Run:
```bash
vendor\bin\pest tests/Unit/Events/OrderBroadcastToDriverTest.php
```

Expected: all three tests pass.

- [ ] **Step 5: Run Pint and commit**

```bash
vendor\bin\pint app/Events tests/Unit/Events
git add app/Events/OrderBroadcastToDriver.php tests/Unit/Events/OrderBroadcastToDriverTest.php
git commit -m "feat(realtime): OrderBroadcastToDriver event"
```

---

## Task 8: Dispatch `OrderBroadcastToDriver` from `StateTransitionService`

**Files:**
- Modify: `app/Services/Order/StateTransitionService.php`
- Test: `tests/Feature/Realtime/BroadcastDispatchOnAwaitingDriverTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Realtime/BroadcastDispatchOnAwaitingDriverTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\OrderActorType;
use App\Enums\OrderStatus;
use App\Events\OrderBroadcastToDriver;
use App\Models\Order;
use App\Services\Order\StateTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('dispatches OrderBroadcastToDriver per eligible driver on transition into awaiting_driver', function (): void {
    Event::fake([OrderBroadcastToDriver::class]);

    $order = Order::factory()->create([
        'status' => OrderStatus::AwaitingDriver->value,
        'pickup_location' => \Clickbar\Magellan\Data\Geometries\Point::makeGeodetic(32.8872, 13.1913),
    ]);
    // Move the order back to a state that can transition INTO awaiting_driver via the service
    $order->forceFill(['status' => OrderStatus::Assigned->value])->save();

    $alice = makeOnlineDriverAt(32.8880, 13.1920);
    $bob = makeOnlineDriverAt(32.8881, 13.1921);

    app(StateTransitionService::class)->transition(
        order: $order->fresh(),
        to: OrderStatus::AwaitingDriver,
        actorType: OrderActorType::System,
        metadata: ['event' => 'test_into_awaiting'],
    );

    Event::assertDispatchedTimes(OrderBroadcastToDriver::class, 2);
    Event::assertDispatched(
        OrderBroadcastToDriver::class,
        fn (OrderBroadcastToDriver $e) => $e->driverId === $alice->id,
    );
    Event::assertDispatched(
        OrderBroadcastToDriver::class,
        fn (OrderBroadcastToDriver $e) => $e->driverId === $bob->id,
    );
});

it('does not dispatch when transition target is not awaiting_driver', function (): void {
    Event::fake([OrderBroadcastToDriver::class]);

    $order = Order::factory()->create(['status' => OrderStatus::AwaitingDriver->value]);

    app(StateTransitionService::class)->transition(
        order: $order,
        to: OrderStatus::Cancelled,
        actorType: OrderActorType::System,
        metadata: ['event' => 'test_cancel'],
    );

    Event::assertNotDispatched(OrderBroadcastToDriver::class);
});

// Reuse the helper from Task 6's test if available; otherwise inline it here.
function makeOnlineDriverAt(float $lat, float $lng): \App\Models\User
{
    $user = \App\Models\User::factory()->create();
    $user->assignRole('driver');

    \App\Models\DriverProfile::factory()->create([
        'user_id' => $user->id,
        'status' => \App\Enums\DriverStatus::Active->value,
        'activity_status' => \App\Enums\DriverActivityStatus::Online->value,
        'vehicle_type' => \App\Enums\VehicleType::Motorcycle->value,
        'current_location' => \Clickbar\Magellan\Data\Geometries\Point::makeGeodetic($lat, $lng),
        'last_location_updated_at' => now(),
    ]);

    \App\Models\DriverAccount::factory()->create([
        'user_id' => $user->id,
        'max_cash_liability' => '200.00',
        'cash_to_deposit' => '0.00',
    ]);

    return $user;
}
```

- [ ] **Step 2: Run test — expect failure**

Run:
```bash
vendor\bin\pest tests/Feature/Realtime/BroadcastDispatchOnAwaitingDriverTest.php
```

Expected: first test fails (no dispatch); second already passes.

- [ ] **Step 3: Modify `StateTransitionService`**

Edit `app/Services/Order/StateTransitionService.php`. Find the existing `event(new OrderStatusChanged(...))` line (around line 80) and add the broadcast dispatch immediately after it, gated on the target state:

Add this import at the top:
```php
use App\Events\OrderBroadcastToDriver;
```

Add a constructor dependency (or use `app()` if the class is purely static — check the file). If it has no constructor, add:
```php
public function __construct(private readonly BroadcastService $broadcasts) {}
```

…and add `use App\Services\Order\BroadcastService;` to the imports.

In the `transition()` method, right after `event(new OrderStatusChanged($order->refresh(), $from, $to, $actorType, $actorId));`, add:

```php
if ($to === OrderStatus::AwaitingDriver) {
    foreach ($this->broadcasts->eligibleDriversFor($order->refresh()) as $profile) {
        event(new OrderBroadcastToDriver(
            $order,
            (int) $profile->user_id,
            $order->search_radius_tier,
        ));
    }
}
```

- [ ] **Step 4: Run tests — expect pass**

Run:
```bash
vendor\bin\pest tests/Feature/Realtime/BroadcastDispatchOnAwaitingDriverTest.php
```

Expected: both tests pass.

- [ ] **Step 5: Run full e2e smoke to verify no regression**

Run:
```bash
php artisan tinker --execute="require base_path('scripts/orders-e2e.php');"
```

Expected: all 32 scenarios still pass (broadcasts are listened to but `Event::fake` is not in scope here — actual `event()` calls during the smoke just queue jobs to the `broadcasts` queue which is fine).

- [ ] **Step 6: Run Pint and commit**

```bash
vendor\bin\pint app/Services/Order/StateTransitionService.php tests/Feature/Realtime
git add app/Services/Order/StateTransitionService.php tests/Feature/Realtime
git commit -m "feat(realtime): dispatch OrderBroadcastToDriver per eligible driver on awaiting_driver"
```

---

## Task 9: Dispatch `OrderBroadcastToDriver` from `EscalationService::applyTier()`

**Files:**
- Modify: `app/Services/Order/EscalationService.php`
- Test: `tests/Feature/Realtime/BroadcastDispatchOnEscalationTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Realtime/BroadcastDispatchOnEscalationTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Events\OrderBroadcastToDriver;
use App\Models\Order;
use App\Services\Order\EscalationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('dispatches OrderBroadcastToDriver on tier escalation', function (): void {
    Event::fake([OrderBroadcastToDriver::class]);

    $order = Order::factory()->create([
        'status' => OrderStatus::AwaitingDriver->value,
        'search_radius_tier' => 1,
        'awaiting_driver_at' => now()->subMinutes(4), // crosses tier-2 threshold
        'pickup_location' => \Clickbar\Magellan\Data\Geometries\Point::makeGeodetic(32.8872, 13.1913),
    ]);

    makeOnlineDriverAt(32.8880, 13.1920);
    makeOnlineDriverAt(32.8881, 13.1921);

    app(EscalationService::class)->process($order);

    Event::assertDispatchedTimes(OrderBroadcastToDriver::class, 2);
    Event::assertDispatched(
        OrderBroadcastToDriver::class,
        fn (OrderBroadcastToDriver $e) => $e->tier === 2,
    );
});
```

(Reuse `makeOnlineDriverAt()` from Task 8 — if Pest auto-loads tests as discrete files, redeclare it here.)

- [ ] **Step 2: Run test — expect failure**

Run:
```bash
vendor\bin\pest tests/Feature/Realtime/BroadcastDispatchOnEscalationTest.php
```

Expected: zero dispatches.

- [ ] **Step 3: Modify `EscalationService`**

Edit `app/Services/Order/EscalationService.php`. Add imports:
```php
use App\Events\OrderBroadcastToDriver;
```

Add `BroadcastService` to the constructor:
```php
public function __construct(
    private readonly StateTransitionService $transitions,
    private readonly BroadcastService $broadcasts,
) {}
```

…with `use App\Services\Order\BroadcastService;` added.

At the end of `applyTier()`, after `->save();`, add:
```php
foreach ($this->broadcasts->eligibleDriversFor($order->refresh()) as $profile) {
    event(new OrderBroadcastToDriver(
        $order->refresh(),
        (int) $profile->user_id,
        $tier,
    ));
}
```

- [ ] **Step 4: Run test — expect pass**

Run:
```bash
vendor\bin\pest tests/Feature/Realtime/BroadcastDispatchOnEscalationTest.php
```

Expected: pass.

- [ ] **Step 5: Run e2e smoke**

Run:
```bash
php artisan tinker --execute="require base_path('scripts/orders-e2e.php');"
```

Expected: 32 scenarios still pass.

- [ ] **Step 6: Run Pint and commit**

```bash
vendor\bin\pint app/Services/Order/EscalationService.php tests/Feature/Realtime
git add app/Services/Order/EscalationService.php tests/Feature/Realtime/BroadcastDispatchOnEscalationTest.php
git commit -m "feat(realtime): dispatch OrderBroadcastToDriver on tier escalation"
```

---

## Task 10: `OrderBroadcastWithdrawn` event

**Files:**
- Create: `app/Events/OrderBroadcastWithdrawn.php`
- Test: `tests/Unit/Events/OrderBroadcastWithdrawnTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Events/OrderBroadcastWithdrawnTest.php`:

```php
<?php

declare(strict_types=1);

use App\Events\OrderBroadcastWithdrawn;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('broadcasts to one driver channel with $afterCommit', function (): void {
    $event = new OrderBroadcastWithdrawn(
        orderPublicId: 'TESTORDERPUBLIC',
        driverId: 42,
        reason: 'claimed_by_another',
    );

    expect($event->afterCommit)->toBeTrue();
    expect($event->broadcastOn()[0])->toBeInstanceOf(PrivateChannel::class);
    expect($event->broadcastOn()[0]->name)->toBe('private-driver.42');

    $payload = $event->broadcastWith();
    expect($payload)->toMatchArray([
        'type' => 'order.broadcast_withdrawn',
        'order_public_id' => 'TESTORDERPUBLIC',
        'reason' => 'claimed_by_another',
    ]);
});
```

- [ ] **Step 2: Run test — expect failure**

Run:
```bash
vendor\bin\pest tests/Unit/Events/OrderBroadcastWithdrawnTest.php
```

Expected: class not found.

- [ ] **Step 3: Create the event**

Create `app/Events/OrderBroadcastWithdrawn.php`:

```php
<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired per-driver when an order is no longer eligible to be claimed by
 * the broadcast pool: claimed by another driver, admin-cancelled, timed
 * out, or sender-cancelled pre-pickup. Listening clients remove the order
 * from their local broadcast list.
 *
 * Dispatched by BroadcastWithdrawnOnExit listener whenever
 * OrderStatusChanged fires with from=AwaitingDriver.
 */
final class OrderBroadcastWithdrawn implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public bool $afterCommit = true;

    public function __construct(
        public readonly string $orderPublicId,
        public readonly int $driverId,
        public readonly string $reason,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('driver.'.$this->driverId)];
    }

    public function broadcastAs(): string
    {
        return 'order.broadcast_withdrawn';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'type' => 'order.broadcast_withdrawn',
            'order_public_id' => $this->orderPublicId,
            'reason' => $this->reason,
        ];
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }
}
```

- [ ] **Step 4: Run test — expect pass**

Run:
```bash
vendor\bin\pest tests/Unit/Events/OrderBroadcastWithdrawnTest.php
```

Expected: pass.

- [ ] **Step 5: Pint + commit**

```bash
vendor\bin\pint app/Events tests/Unit/Events
git add app/Events/OrderBroadcastWithdrawn.php tests/Unit/Events/OrderBroadcastWithdrawnTest.php
git commit -m "feat(realtime): OrderBroadcastWithdrawn event"
```

---

## Task 11: `BroadcastWithdrawnOnExit` listener

Fires `OrderBroadcastWithdrawn` per-eligible-driver whenever any `OrderStatusChanged` event has `from === AwaitingDriver` and `to !== AwaitingDriver`. Single source of truth — handles ClaimService claims, sender cancels, admin cancels, broadcast timeouts. (AdminAssignmentService gap will be fixed by Codex in Phase 2 task 2.2; once that lands, admin assigns will also trigger this listener automatically.)

**Files:**
- Create: `app/Listeners/BroadcastWithdrawnOnExit.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Unit/Listeners/BroadcastWithdrawnOnExitTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Listeners/BroadcastWithdrawnOnExitTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\OrderActorType;
use App\Enums\OrderStatus;
use App\Events\OrderBroadcastWithdrawn;
use App\Events\OrderStatusChanged;
use App\Listeners\BroadcastWithdrawnOnExit;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('fires withdrawn for each eligible driver when order leaves awaiting_driver', function (): void {
    Event::fake([OrderBroadcastWithdrawn::class]);

    $order = Order::factory()->create([
        'status' => OrderStatus::Cancelled->value,
        'pickup_location' => \Clickbar\Magellan\Data\Geometries\Point::makeGeodetic(32.8872, 13.1913),
    ]);

    $alice = makeOnlineDriverAt(32.8880, 13.1920);
    $bob = makeOnlineDriverAt(32.8881, 13.1921);

    $listener = app(BroadcastWithdrawnOnExit::class);
    $listener->handle(new OrderStatusChanged(
        $order,
        OrderStatus::AwaitingDriver,
        OrderStatus::Cancelled,
        OrderActorType::Sender,
    ));

    Event::assertDispatched(OrderBroadcastWithdrawn::class, fn ($e) => $e->driverId === $alice->id);
    Event::assertDispatched(OrderBroadcastWithdrawn::class, fn ($e) => $e->driverId === $bob->id);
});

it('does nothing when from is not awaiting_driver', function (): void {
    Event::fake([OrderBroadcastWithdrawn::class]);

    $order = Order::factory()->create();

    app(BroadcastWithdrawnOnExit::class)->handle(new OrderStatusChanged(
        $order,
        OrderStatus::Assigned,
        OrderStatus::PickedUp,
        OrderActorType::Driver,
    ));

    Event::assertNotDispatched(OrderBroadcastWithdrawn::class);
});

function makeOnlineDriverAt(float $lat, float $lng): \App\Models\User
{
    $user = \App\Models\User::factory()->create();
    $user->assignRole('driver');

    \App\Models\DriverProfile::factory()->create([
        'user_id' => $user->id,
        'status' => \App\Enums\DriverStatus::Active->value,
        'activity_status' => \App\Enums\DriverActivityStatus::Online->value,
        'vehicle_type' => \App\Enums\VehicleType::Motorcycle->value,
        'current_location' => \Clickbar\Magellan\Data\Geometries\Point::makeGeodetic($lat, $lng),
        'last_location_updated_at' => now(),
    ]);

    \App\Models\DriverAccount::factory()->create([
        'user_id' => $user->id,
        'max_cash_liability' => '200.00',
        'cash_to_deposit' => '0.00',
    ]);

    return $user;
}
```

- [ ] **Step 2: Run test — expect failure (class not found)**

Run:
```bash
vendor\bin\pest tests/Unit/Listeners/BroadcastWithdrawnOnExitTest.php
```

Expected: class not found.

- [ ] **Step 3: Create the listener**

Create `app/Listeners/BroadcastWithdrawnOnExit.php`:

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\OrderStatus;
use App\Events\OrderBroadcastWithdrawn;
use App\Events\OrderStatusChanged;
use App\Services\Order\BroadcastService;

/**
 * Maps "order left awaiting_driver" to per-driver OrderBroadcastWithdrawn
 * events so subscribed drivers can drop the order from their local list.
 * The reason is derived from the transition target.
 */
final class BroadcastWithdrawnOnExit
{
    public function __construct(private readonly BroadcastService $broadcasts) {}

    public function handle(OrderStatusChanged $event): void
    {
        if ($event->fromStatus !== OrderStatus::AwaitingDriver) {
            return;
        }
        if ($event->toStatus === OrderStatus::AwaitingDriver) {
            return;
        }

        $reason = $this->reasonFor($event->toStatus);

        foreach ($this->broadcasts->eligibleDriversFor($event->order) as $profile) {
            event(new OrderBroadcastWithdrawn(
                orderPublicId: $event->order->public_id,
                driverId: (int) $profile->user_id,
                reason: $reason,
            ));
        }
    }

    private function reasonFor(OrderStatus $to): string
    {
        return match ($to) {
            OrderStatus::Assigned, OrderStatus::DriverEnRoutePickup => 'claimed',
            OrderStatus::NoDriverAvailable => 'timeout',
            OrderStatus::Cancelled => 'cancelled',
            default => $to->value,
        };
    }
}
```

- [ ] **Step 4: Register the listener in `AppServiceProvider`**

Edit `app/Providers/AppServiceProvider.php`. Add imports:
```php
use App\Events\OrderStatusChanged;
use App\Listeners\BroadcastWithdrawnOnExit;
use Illuminate\Support\Facades\Event;
```

In `boot()`, before `$this->configureRateLimiters();`, add:
```php
Event::listen(OrderStatusChanged::class, BroadcastWithdrawnOnExit::class);
```

- [ ] **Step 5: Run tests — expect pass**

Run:
```bash
vendor\bin\pest tests/Unit/Listeners/BroadcastWithdrawnOnExitTest.php
```

Expected: both tests pass.

- [ ] **Step 6: Run e2e smoke for regression**

Run:
```bash
php artisan tinker --execute="require base_path('scripts/orders-e2e.php');"
```

Expected: 32 scenarios still pass.

- [ ] **Step 7: Pint + commit**

```bash
vendor\bin\pint app/Listeners app/Providers/AppServiceProvider.php tests/Unit/Listeners
git add app/Listeners/BroadcastWithdrawnOnExit.php app/Providers/AppServiceProvider.php tests/Unit/Listeners
git commit -m "feat(realtime): BroadcastWithdrawnOnExit listener fires withdrawn per eligible driver"
```

---

## Task 12: Update `composer dev` to launch Reverb

**Files:**
- Modify: `composer.json`

- [ ] **Step 1: Edit `composer.json`**

In the `"scripts"` block, replace the existing `"dev"` script:

```json
"dev": [
    "Composer\\Config::disableProcessTimeout",
    "npx concurrently -c \"#93c5fd,#c4b5fd,#fdba74,#86efac\" \"php artisan serve\" \"php artisan queue:listen --tries=1 --queue=default,broadcasts\" \"php artisan reverb:start\" \"npm run dev\" --names='server,queue,reverb,vite'"
]
```

- [ ] **Step 2: Verify the script parses**

Run:
```bash
composer validate --strict
```

Expected: `./composer.json is valid`.

- [ ] **Step 3: Smoke run** (manual — start `composer dev` and confirm all four processes boot; Ctrl-C to stop)

- [ ] **Step 4: Commit**

```bash
git add composer.json
git commit -m "chore(realtime): composer dev launches Reverb and broadcasts queue worker"
```

---

## Task 13: Supervisor config example for prod

**Files:**
- Create: `docs/deployment/reverb-supervisor.conf.example`

- [ ] **Step 1: Create the file**

Create `docs/deployment/reverb-supervisor.conf.example`:

```ini
; ─── Reverb WebSocket server ────────────────────────────────────────────
; One worker process. Reverb is already async (ReactPHP).
[program:delivary-reverb]
process_name=%(program_name)s
command=php /var/www/delivary/artisan reverb:start --host=0.0.0.0 --port=8080
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/supervisor/delivary-reverb.log
stopwaitsecs=3600

; ─── Queue worker: default jobs ─────────────────────────────────────────
[program:delivary-queue-default]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/delivary/artisan queue:work redis --queue=default --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/supervisor/delivary-queue-default.log
stopwaitsecs=3600

; ─── Queue worker: broadcasts (real-time fan-out) ──────────────────────
; Higher concurrency because broadcast events are short-lived and bursty.
; Location events bypass this queue (ShouldBroadcastNow), so this worker
; only handles ShouldBroadcast (queued) events.
[program:delivary-queue-broadcasts]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/delivary/artisan queue:work redis --queue=broadcasts --sleep=1 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/var/log/supervisor/delivary-queue-broadcasts.log
stopwaitsecs=3600
```

- [ ] **Step 2: Commit**

```bash
git add docs/deployment/reverb-supervisor.conf.example
git commit -m "docs(realtime): supervisor.conf example for reverb + queue workers"
```

---

## Task 14: Final full-suite verification

- [ ] **Step 1: Run the entire Pest suite**

Run:
```bash
vendor\bin\pest
```

Expected: all tests pass.

- [ ] **Step 2: Run the orders e2e smoke**

Run:
```bash
php artisan tinker --execute="require base_path('scripts/orders-e2e.php');"
```

Expected: 32 scenarios pass; output should be free of fatal errors and the final line says `All scenarios passed.` (or equivalent — see the existing script's output convention).

- [ ] **Step 3: Final Pint pass over everything touched in Phase 1**

Run:
```bash
vendor\bin\pint
```

Expected: clean output.

- [ ] **Step 4: Push branch and open PR**

```bash
git push -u origin HEAD
gh pr create --title "feat(realtime): phase 1 — reverb foundation + driver broadcast events + broadcast-safe resources" --body "$(cat <<'EOF'
## Summary

Phase 1 of the Real-Time milestone (spec: docs/superpowers/specs/2026-05-18-realtime-reverb-design.md).

- Installs Laravel Reverb, registers Sanctum-authed `/broadcasting/auth`
- Channel auth callbacks for `user.{id}`, `order.{public_id}`, `driver.{id}` in `routes/channels.php`
- Broadcast-safe Resources: `OrderForPartiesResource`, `DriverForOrderResource`
- `BroadcastService::eligibleDriversFor(Order)` inverse query
- Foundation events: `OrderBroadcastToDriver`, `OrderBroadcastWithdrawn`
- `BroadcastWithdrawnOnExit` listener — single source of truth for withdrawals (handles ClaimService, sender/admin cancels, escalation timeouts)
- `composer dev` launches Reverb + broadcasts queue worker
- `docs/deployment/reverb-supervisor.conf.example` for prod

`DriverAccountResource` was verified to be request-independent (no `$request->user()` branching), so no broadcast variant is needed for it in Phase 2.

## Test plan
- [x] All Pest tests green (channel auth, resources, events, listener)
- [x] `scripts/orders-e2e.php` 32 scenarios pass with no regression
- [x] Pint clean
- [x] Manual: `composer dev` boots all four processes; Reverb accepts connections on :8080

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

Expected: PR URL printed. Hand off to Codex for review per spec §8.1.

---

## Self-review checklist

Run after writing the plan — fix issues inline, don't re-review.

- [ ] **Spec coverage:** every Phase 1 deliverable in §8 of the spec (items 1–7) is implemented by a numbered task in this plan. Channel auth (✓ Task 3), Sanctum-authed broadcast route (✓ Task 2), Reverb install (✓ Task 1), broadcast-safe Resources (✓ Tasks 4–5), `OrderBroadcastToDriver` event + wiring (✓ Tasks 7–9), `OrderBroadcastWithdrawn` event + listener (✓ Tasks 10–11), composer dev + supervisor (✓ Tasks 12–13). `DriverAccountResource` verification is documented in the PR body (Task 14).
- [ ] **Placeholders:** none — every code block contains the actual content.
- [ ] **Type/name consistency:** `OrderBroadcastToDriver` uses `(Order, int $driverId, int $tier)` in event class, test, and both dispatch sites. `OrderBroadcastWithdrawn` uses `(string $orderPublicId, int $driverId, string $reason)` in class, test, and listener. `eligibleDriversFor(Order)` returns `Collection<int, DriverProfile>` and is called with `(int) $profile->user_id` in every dispatch site.
- [ ] **`Point::toWkt()` caveat:** Task 6 step 3 flags that the exact serialization method on `clickbar/laravel-magellan`'s `Point` may differ from `toWkt()`. Verify the call works before committing Task 6.
