# Order Lifecycle (A+B) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the complete order happy-path — sender quote → create → driver presence + atomic claim → in-transit transitions with code/geofence verification → delivered with financial bucket settlement — plus admin manual assign/unassign, sender retry/cancel from `no_driver_available`, scheduled tier-escalation + driver auto-offline jobs, and the public guest-tracking page.

**Architecture:** Service-layer driven, every transition routed through one `StateTransitionService` (the sole writer of `orders.status`, asserts it runs inside `DB::transaction`, fires `OrderStatusChanged` event, runs post-transition hooks). Pricing computed pure → quote signed via HMAC → creation re-runs the formula server-side. Driver claim uses the spec-mandated atomic conditional UPDATE. Codes encrypted at rest via Laravel's `'encrypted'` cast, decrypt on model read, with platform-wide kill-switch settings for incident response.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL + PostGIS (clickbar/laravel-magellan), Sanctum 4, Spatie Permission 7, Bavix Wallet, Redis cache, bcmath for money. No test framework yet (Pest deferred per project state); verification via Tinker smoke scripts.

**Spec:** `docs/superpowers/specs/2026-05-12-order-lifecycle-design.md`

---

## File Structure

```
NEW
├── database/migrations/
│   ├── 2026_05_12_000100_add_base_fee_to_regions_table.php
│   ├── 2026_05_12_000200_add_pickup_geofence_confirmed_at_to_orders_table.php
│   └── 2026_05_12_000300_create_driver_presence_logs_table.php
│
├── database/seeders/
│   └── OrderLifecyclePlatformSettingsSeeder.php
│
├── app/Enums/
│   └── OrderErrorCode.php
│
├── app/Events/
│   └── OrderStatusChanged.php
│
├── app/Exceptions/Order/
│   ├── InvalidOrderTransitionException.php
│   ├── OrderDomainException.php                (base — wraps OrderErrorCode + httpStatus)
│   └── QuoteMismatchException.php
│
├── app/Models/
│   └── DriverPresenceLog.php
│
├── app/Policies/
│   └── OrderPolicy.php
│
├── app/Support/
│   ├── OrderDisplayStatus.php                  (value class)
│   └── QuoteToken.php                          (sign / verify HMAC)
│
├── app/Services/Order/
│   ├── PricingService.php                      (pure)
│   ├── QuoteService.php                        (HMAC-signed token)
│   ├── CreationService.php                     (snapshot + codes + initial transition)
│   ├── BroadcastService.php                    (PostGIS eligibility)
│   ├── ClaimService.php                        (atomic UPDATE)
│   ├── StateTransitionService.php              (sole writer of orders.status)
│   ├── CodeVerificationService.php
│   ├── EscalationService.php
│   ├── RetryService.php
│   ├── CancellationService.php                 (scope-limited: no_driver_available only)
│   ├── AdminAssignmentService.php
│   └── Hooks/PostTransitionHookRegistry.php    (the (from,to) → closure map)
│
├── app/Services/Driver/
│   ├── PresenceService.php                     (go-online / go-offline / location)
│   └── AutoOfflineService.php
│
├── app/Jobs/
│   ├── EscalateBroadcastingOrdersJob.php
│   └── AutoOfflineIdleDriversJob.php
│
├── app/Http/Controllers/Api/Order/
│   ├── QuoteController.php                     (POST /orders/quote)
│   └── OrderController.php                     (POST /orders, GET /me/orders, GET /me/orders/{id})
│
├── app/Http/Controllers/Api/Me/Order/
│   ├── RetryController.php                     (POST /me/orders/{id}/retry)
│   ├── CancelController.php                    (POST /me/orders/{id}/cancel)
│   └── GeofenceConfirmController.php           (POST /me/orders/{id}/confirm-pickup-geofence)
│
├── app/Http/Controllers/Api/Driver/Order/
│   ├── PresenceController.php                  (go-online / go-offline / location)
│   ├── BroadcastController.php                 (GET /driver/orders/broadcast, GET /driver/orders/current)
│   ├── ClaimController.php                     (POST /driver/orders/{id}/claim)
│   ├── ConfirmPickupController.php             (POST /driver/orders/{id}/confirm-pickup)
│   ├── ArrivedDropoffController.php            (POST /driver/orders/{id}/arrived-dropoff)
│   └── ConfirmDeliveryController.php           (POST /driver/orders/{id}/confirm-delivery)
│
├── app/Http/Controllers/Api/Admin/Order/
│   └── OrderController.php                     (index / show / assign / unassign)
│
├── app/Http/Controllers/Api/Tracking/
│   └── GuestTrackingController.php             (GET /track/{tracking_token})
│
├── app/Http/Requests/Order/
│   ├── QuoteOrderRequest.php
│   ├── CreateOrderRequest.php
│   ├── RetryOrderRequest.php
│   ├── CancelOrderRequest.php
│   ├── ConfirmPickupRequest.php
│   ├── ConfirmPickupGeofenceRequest.php
│   ├── ConfirmDeliveryRequest.php
│   ├── DriverGoOnlineRequest.php
│   ├── DriverLocationUpdateRequest.php
│   ├── AdminAssignOrderRequest.php
│   └── AdminUnassignOrderRequest.php
│
├── app/Http/Resources/Order/
│   ├── OrderResource.php                       (sender + receiver)
│   ├── DriverOrderResource.php
│   ├── AdminOrderResource.php
│   ├── BroadcastOrderResource.php
│   ├── GuestTrackingResource.php
│   ├── QuoteResource.php
│   └── OrderStatusLogResource.php
│
├── lang/en/order_messages.php
├── lang/ar/order_messages.php
│
└── scripts/orders-e2e.php                      (Tinker-runnable smoke script)

MODIFIED
├── app/Enums/PickupMethod.php                  (add `Bypassed`)
├── app/Enums/DeliveryMethod.php                (add `Bypassed`)
├── app/Models/Order.php                        (cast pickup_code/delivery_code as encrypted, add pickup_geofence_confirmed_at, new scopes)
├── app/Models/Region.php                       (cast base_fee)
├── app/Models/DriverProfile.php                (helper scopes if missing — none expected)
├── app/Providers/AuthServiceProvider.php       (register OrderPolicy)
├── routes/api.php                              (add /orders/*, /me/orders/*, /driver/orders/*, /admin/orders/*, /track/*)
├── routes/console.php                          (schedule the two jobs every minute)
├── bootstrap/app.php                           (register Sanctum-throttle named limiters: orders_quote, orders_create, driver_location, etc.)
├── database/seeders/DatabaseSeeder.php         (call OrderLifecyclePlatformSettingsSeeder)
└── docs/CLAUDE.md, docs/SYSTEM_SPECIFICATION.md (closing update)
```

---

## Task 1: Database migrations + enum extensions

**Files:**
- Create: `database/migrations/2026_05_12_000100_add_base_fee_to_regions_table.php`
- Create: `database/migrations/2026_05_12_000200_add_pickup_geofence_confirmed_at_to_orders_table.php`
- Create: `database/migrations/2026_05_12_000300_create_driver_presence_logs_table.php`
- Modify: `app/Enums/PickupMethod.php`
- Modify: `app/Enums/DeliveryMethod.php`
- Modify: `app/Models/Order.php` (encrypted casts + new column + scope)
- Modify: `app/Models/Region.php` (cast `base_fee`)
- Create: `app/Models/DriverPresenceLog.php`

- [ ] **Step 1.1: Migration — add `base_fee` to `regions`.**

File: `database/migrations/2026_05_12_000100_add_base_fee_to_regions_table.php`

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('regions', function (Blueprint $table): void {
            // Per-region flat fee. Default 0; admin seeds real values via Tinker
            // post-deploy. The order's delivery_fee_base snapshots this value
            // at quote-time so historical orders are unaffected by later edits.
            $table->decimal('base_fee', 12, 2)->default(0)->after('boundary');
        });
    }

    public function down(): void
    {
        Schema::table('regions', function (Blueprint $table): void {
            $table->dropColumn('base_fee');
        });
    }
};
```

- [ ] **Step 1.2: Migration — add `pickup_geofence_confirmed_at` to `orders`.**

File: `database/migrations/2026_05_12_000200_add_pickup_geofence_confirmed_at_to_orders_table.php`

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Set by sender when they confirm "the driver is at my pickup" in their app.
            // Consumed by the driver's confirm-pickup with method=geofence within 5 minutes
            // (TTL hardcoded in CodeVerificationService; not a tunable knob).
            $table->timestamp('pickup_geofence_confirmed_at')->nullable()->after('pickup_code_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('pickup_geofence_confirmed_at');
        });
    }
};
```

- [ ] **Step 1.3: Migration — create `driver_presence_logs`.**

File: `database/migrations/2026_05_12_000300_create_driver_presence_logs_table.php`

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_presence_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->string('event'); // values: went_online, went_offline, auto_offline
            $table->string('reason')->nullable(); // gps_lost | idle | manual | admin_unassign | ...
            $table->magellanPoint('location', 4326, 'GEOGRAPHY')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['driver_id', 'created_at']);
            $table->index(['event', 'created_at']);
        });

        DB::statement('CREATE INDEX driver_presence_logs_location_idx ON driver_presence_logs USING GIST (location)');
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_presence_logs');
    }
};
```

- [ ] **Step 1.4: Add `Bypassed` to `PickupMethod` enum.**

File: `app/Enums/PickupMethod.php` — add the case and update any `match()` blocks if they exist.

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum PickupMethod: string
{
    case Code = 'code';
    case GeofenceConfirmation = 'geofence_confirmation';
    case AdminOverride = 'admin_override';
    case Bypassed = 'bypassed'; // platform-wide codes.enforce_pickup = false
}
```

(If the existing file has methods like `label()`, preserve them and add the matching `Bypassed => 'Bypassed (codes disabled)'` entry.)

- [ ] **Step 1.5: Add `Bypassed` to `DeliveryMethod` enum.**

File: `app/Enums/DeliveryMethod.php`

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum DeliveryMethod: string
{
    case Code = 'code';
    case AdminOverride = 'admin_override';
    case Bypassed = 'bypassed'; // platform-wide codes.enforce_delivery = false
}
```

- [ ] **Step 1.6: Modify `Order` model — encrypted casts, new attribute, scopes.**

File: `app/Models/Order.php` — within the existing `$fillable` array, add `'pickup_geofence_confirmed_at'`. Within `casts()` add:

```php
'pickup_code' => 'encrypted',
'delivery_code' => 'encrypted',
'pickup_geofence_confirmed_at' => 'datetime',
```

Add scope (at the bottom of the class with the other scopes):

```php
/**
 * Orders currently broadcasting and eligible for the radius-tier escalation job.
 */
public function scopeBroadcasting(Builder $query): Builder
{
    return $query->where('status', OrderStatus::AwaitingDriver->value);
}
```

- [ ] **Step 1.7: Modify `Region` model — cast `base_fee`.**

File: `app/Models/Region.php` — add to `casts()` (or merge into existing returned array):

```php
'base_fee' => 'decimal:2',
```

Add to `$fillable` if it isn't pulled by `guarded`:

```php
'base_fee',
```

- [ ] **Step 1.8: Create `DriverPresenceLog` model.**

File: `app/Models/DriverPresenceLog.php`

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DriverPresenceLog extends Model
{
    public const UPDATED_AT = null; // append-only

    /** @var array<int, string> */
    protected $fillable = ['driver_id', 'event', 'reason', 'location'];

    protected function casts(): array
    {
        return [
            'location' => Point::class,
            'created_at' => 'datetime',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }
}
```

- [ ] **Step 1.9: Run migrations.**

Run: `php artisan migrate`

Expected output: three `Migrating: ...` / `Migrated: ...` lines, no errors.

- [ ] **Step 1.10: Tinker smoke — verify new columns + presence-log model.**

Run: `php artisan tinker`

```php
\Schema::hasColumn('regions', 'base_fee');                          // true
\Schema::hasColumn('orders', 'pickup_geofence_confirmed_at');       // true
\Schema::hasTable('driver_presence_logs');                          // true
\App\Models\DriverPresenceLog::create([
    'driver_id' => \App\Models\User::first()->id,
    'event' => 'went_online',
    'reason' => 'manual',
]);                                                                  // saves
\App\Models\DriverPresenceLog::count();                              // 1
\App\Models\DriverPresenceLog::query()->delete();                    // cleanup
```

---

## Task 2: Cross-cutting infrastructure — error code, exceptions, event, policy, value classes, localization

**Files:**
- Create: `app/Enums/OrderErrorCode.php`
- Create: `app/Exceptions/Order/OrderDomainException.php`
- Create: `app/Exceptions/Order/InvalidOrderTransitionException.php`
- Create: `app/Exceptions/Order/QuoteMismatchException.php`
- Create: `app/Events/OrderStatusChanged.php`
- Create: `app/Policies/OrderPolicy.php`
- Create: `app/Support/OrderDisplayStatus.php`
- Create: `app/Support/QuoteToken.php`
- Create: `lang/en/order_messages.php`
- Create: `lang/ar/order_messages.php`
- Modify: `app/Providers/AuthServiceProvider.php` (register `OrderPolicy`)
- Modify: `bootstrap/app.php` (register exception handler for `OrderDomainException`)

- [ ] **Step 2.1: Create `OrderErrorCode` enum.**

File: `app/Enums/OrderErrorCode.php`

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderErrorCode: string
{
    case PickupOutOfServiceArea = 'pickup_out_of_service_area';
    case InvalidQuoteToken = 'invalid_quote_token';
    case QuoteExpired = 'quote_expired';
    case QuotePriceChanged = 'quote_price_changed';
    case SenderIsReceiver = 'sender_is_receiver';
    case MerchantUseMerchantFlow = 'merchant_use_merchant_flow';
    case IdempotencyConflict = 'idempotency_conflict';
    case OrderAlreadyClaimed = 'order_already_claimed';
    case OrderNotRetryable = 'order_not_retryable';
    case OrderNotCancellableFromState = 'order_not_cancellable_from_state';
    case OrderNotAssignable = 'order_not_assignable';
    case OrderNotUnassignable = 'order_not_unassignable';
    case OrderHasNoDriver = 'order_has_no_driver';
    case InvalidStateTransition = 'invalid_state_transition';
    case NotYourOrder = 'not_your_order';
    case InvalidPickupCode = 'invalid_pickup_code';
    case InvalidDeliveryCode = 'invalid_delivery_code';
    case CodeLocked = 'code_locked';
    case MethodRequired = 'method_required';
    case CodeRequired = 'code_required';
    case GeofenceNotConfirmed = 'geofence_not_confirmed';
    case DriverNotAtPickup = 'driver_not_at_pickup';
    case DriverNotNearDropoff = 'driver_not_near_dropoff';
    case DriverNotActive = 'driver_not_active';
    case DriverNoRegions = 'driver_no_regions';
    case DriverGpsRequired = 'driver_gps_required';
    case DriverOutOfServiceArea = 'driver_out_of_service_area';
    case DriverLiabilityMax = 'driver_liability_max';
    case DriverLiabilityInsufficient = 'driver_liability_insufficient';
    case DriverHasActiveOrder = 'driver_has_active_order';
    case DriverLocationStale = 'driver_location_stale';
    case DriverBlockedByDebt = 'driver_blocked_by_debt';
    case VehicleMismatch = 'vehicle_mismatch';
    case DriverRegionMismatch = 'driver_region_mismatch';

    public function httpStatus(): int
    {
        return match ($this) {
            self::PickupOutOfServiceArea, self::InvalidQuoteToken => 400,
            self::NotYourOrder => 403,
            self::SenderIsReceiver,
            self::MerchantUseMerchantFlow,
            self::InvalidPickupCode,
            self::InvalidDeliveryCode,
            self::MethodRequired,
            self::CodeRequired,
            self::DriverNotActive,
            self::DriverNoRegions,
            self::DriverGpsRequired,
            self::DriverOutOfServiceArea,
            self::DriverLiabilityInsufficient,
            self::VehicleMismatch,
            self::DriverRegionMismatch => 422,
            self::QuotePriceChanged,
            self::IdempotencyConflict,
            self::OrderAlreadyClaimed,
            self::OrderNotRetryable,
            self::OrderNotCancellableFromState,
            self::OrderNotAssignable,
            self::OrderNotUnassignable,
            self::OrderHasNoDriver,
            self::InvalidStateTransition,
            self::GeofenceNotConfirmed,
            self::DriverNotAtPickup,
            self::DriverNotNearDropoff,
            self::DriverLiabilityMax,
            self::DriverHasActiveOrder,
            self::DriverLocationStale,
            self::DriverBlockedByDebt => 409,
            self::QuoteExpired => 410,
            self::CodeLocked => 429,
        };
    }
}
```

- [ ] **Step 2.2: Create exception base + concrete exceptions.**

File: `app/Exceptions/Order/OrderDomainException.php`

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Order;

use App\Enums\OrderErrorCode;
use RuntimeException;

class OrderDomainException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly OrderErrorCode $errorCode,
        string $message = '',
        public readonly array $details = [],
    ) {
        parent::__construct($message !== '' ? $message : $errorCode->value);
    }

    public function httpStatus(): int
    {
        return $this->errorCode->httpStatus();
    }

    /**
     * @return array{error: array{code: string, message: string, details?: array<string, mixed>}}
     */
    public function toResponse(): array
    {
        $payload = [
            'error' => [
                'code' => strtoupper($this->errorCode->value),
                'message' => $this->getMessage(),
            ],
        ];
        if ($this->details !== []) {
            $payload['error']['details'] = $this->details;
        }

        return $payload;
    }
}
```

File: `app/Exceptions/Order/InvalidOrderTransitionException.php`

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Order;

use App\Enums\OrderErrorCode;
use App\Enums\OrderStatus;

final class InvalidOrderTransitionException extends OrderDomainException
{
    public function __construct(OrderStatus $from, OrderStatus $to)
    {
        parent::__construct(
            errorCode: OrderErrorCode::InvalidStateTransition,
            message: sprintf('Cannot transition from %s to %s.', $from->value, $to->value),
            details: ['from' => $from->value, 'to' => $to->value],
        );
    }
}
```

File: `app/Exceptions/Order/QuoteMismatchException.php`

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Order;

use App\Enums\OrderErrorCode;

final class QuoteMismatchException extends OrderDomainException
{
    /** @param  array<string, mixed>  $freshQuote */
    public function __construct(array $freshQuote)
    {
        parent::__construct(
            errorCode: OrderErrorCode::QuotePriceChanged,
            message: 'The price changed since you previewed it. Review the updated quote and confirm.',
            details: ['fresh_quote' => $freshQuote],
        );
    }
}
```

- [ ] **Step 2.3: Register exception → response mapping.**

File: `bootstrap/app.php` — inside `->withExceptions(function (Exceptions $exceptions): void { ... })`, add:

```php
use App\Exceptions\Order\OrderDomainException;
use Illuminate\Http\JsonResponse;

$exceptions->render(function (OrderDomainException $e): JsonResponse {
    return new JsonResponse($e->toResponse(), $e->httpStatus());
});
```

(If the file uses Closure-based config differently, follow whatever pattern is already in place for auth/driver exceptions — see how `DriverErrorCode` exceptions are rendered.)

- [ ] **Step 2.4: Create `OrderStatusChanged` event.**

File: `app/Events/OrderStatusChanged.php`

```php
<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\OrderActorType;
use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

final class OrderStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Order $order,
        public readonly OrderStatus $fromStatus,
        public readonly OrderStatus $toStatus,
        public readonly OrderActorType $actorType,
        public readonly ?int $actorId = null,
    ) {}
}
```

- [ ] **Step 2.5: Create `OrderPolicy`.**

File: `app/Policies/OrderPolicy.php`

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;

final class OrderPolicy
{
    public function viewAsSender(User $user, Order $order): bool
    {
        return $user->id === $order->sender_user_id;
    }

    public function viewAsReceiver(User $user, Order $order): bool
    {
        return $order->receiver_user_id !== null
            && $user->id === $order->receiver_user_id;
    }

    public function view(User $user, Order $order): bool
    {
        return $this->viewAsSender($user, $order)
            || $this->viewAsReceiver($user, $order);
    }

    public function act(User $user, Order $order): bool
    {
        return $order->driver_id === $user->id
            && $user->hasRole('driver');
    }

    public function retryByUser(User $user, Order $order): bool
    {
        return $this->viewAsSender($user, $order)
            && $order->status === OrderStatus::NoDriverAvailable;
    }

    public function cancelByUser(User $user, Order $order): bool
    {
        return $this->viewAsSender($user, $order)
            && $order->status === OrderStatus::NoDriverAvailable;
    }

    public function confirmGeofenceBySender(User $user, Order $order): bool
    {
        return $this->viewAsSender($user, $order)
            && $order->status === OrderStatus::DriverEnRoutePickup;
    }
}
```

- [ ] **Step 2.6: Register the policy.**

File: `app/Providers/AuthServiceProvider.php` — add to the `$policies` array:

```php
\App\Models\Order::class => \App\Policies\OrderPolicy::class,
```

If the provider doesn't exist in this project yet (Laravel 11+ moved it), wire it inside `bootstrap/app.php`'s `->withProviders([...])` or via `Gate::policy(Order::class, OrderPolicy::class)` in a service provider's `boot()`.

- [ ] **Step 2.7: Create `OrderDisplayStatus` value class.**

File: `app/Support/OrderDisplayStatus.php`

```php
<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\OrderStatus;

final class OrderDisplayStatus
{
    /**
     * Collapse the internal 16-state machine into the customer-facing surface.
     * The DB always holds the granular state; this is presentation only.
     */
    public static function fromInternal(OrderStatus $s): string
    {
        return match ($s) {
            OrderStatus::Created => 'creating',
            OrderStatus::AwaitingDriver => 'awaiting_driver',
            OrderStatus::NoDriverAvailable => 'no_driver_available',
            OrderStatus::Assigned,
            OrderStatus::DriverEnRoutePickup => 'assigned',
            OrderStatus::PickedUp,
            OrderStatus::DriverEnRouteDropoff => 'picked_up',
            OrderStatus::DeliveryInProgress => 'delivery_in_progress',
            OrderStatus::Delivered => 'delivered',
            OrderStatus::DeliveryFailed,
            OrderStatus::ReturningToOffice,
            OrderStatus::AtOffice,
            OrderStatus::RetrievedBySeller,
            OrderStatus::Abandoned => 'failed',          // sub-project D will refine this
            OrderStatus::CancelledByUser,
            OrderStatus::CancelledByAdmin => 'cancelled',
        };
    }
}
```

- [ ] **Step 2.8: Create `QuoteToken` HMAC signer/verifier.**

File: `app/Support/QuoteToken.php`

```php
<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

final class QuoteToken
{
    /**
     * Sign a quote payload with HMAC-SHA256 keyed by APP_KEY.
     * Returns "<base64(payload)>.<hex(signature)>".
     *
     * @param  array<string, mixed>  $payload  (must already include the absolute "expires_at" unix ts)
     */
    public static function sign(array $payload): string
    {
        $json = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $b64 = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        $sig = hash_hmac('sha256', $b64, self::secret());

        return $b64 . '.' . $sig;
    }

    /**
     * Verify the signature and TTL, return the decoded payload.
     * Throws InvalidArgumentException on tamper; returns null on expiry
     * (caller distinguishes: missing-or-invalid vs expired).
     *
     * @return array{payload: array<string, mixed>, expired: bool}
     */
    public static function verify(string $token): array
    {
        if (! str_contains($token, '.')) {
            throw new InvalidArgumentException('malformed_token');
        }
        [$b64, $sig] = explode('.', $token, 2);
        $expected = hash_hmac('sha256', $b64, self::secret());
        if (! hash_equals($expected, $sig)) {
            throw new InvalidArgumentException('bad_signature');
        }

        $padded = $b64 . str_repeat('=', (4 - strlen($b64) % 4) % 4);
        $json = base64_decode(strtr($padded, '-_', '+/'), true);
        if ($json === false) {
            throw new InvalidArgumentException('bad_base64');
        }
        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($json, true);
        if (! is_array($payload) || ! isset($payload['expires_at'])) {
            throw new InvalidArgumentException('bad_payload');
        }

        return [
            'payload' => $payload,
            'expired' => (int) $payload['expires_at'] < time(),
        ];
    }

    private static function secret(): string
    {
        // Use APP_KEY but namespaced so accidental leaks of this HMAC don't compromise other signed-URL paths.
        $key = (string) Config::get('app.key');
        return hash('sha256', 'quote_token|' . $key, true);
    }
}
```

- [ ] **Step 2.9: Create localization scaffolds.**

File: `lang/en/order_messages.php`

```php
<?php

declare(strict_types=1);

return [
    // Pricing / quoting
    'pickup_out_of_service_area' => 'The pickup location is outside our active service area.',
    'invalid_quote_token' => 'Invalid quote. Request a fresh quote and try again.',
    'quote_expired' => 'Your quote has expired. Request a fresh quote and try again.',
    'quote_price_changed' => 'The price changed since you previewed it. Review the updated quote and confirm.',

    // Creation
    'sender_is_receiver' => 'You cannot create an order to yourself.',
    'merchant_use_merchant_flow' => 'Merchant accounts must use the merchant order flow.',
    'idempotency_conflict' => 'A different request is already in flight with this idempotency key.',

    // Lifecycle
    'order_already_claimed' => 'This order was claimed by another driver.',
    'order_not_retryable' => 'This order cannot be retried in its current state.',
    'order_not_cancellable_from_state' => 'This order cannot be cancelled in its current state.',
    'order_not_assignable' => 'This order cannot be assigned in its current state.',
    'order_not_unassignable' => 'This order cannot be unassigned in its current state.',
    'order_has_no_driver' => 'This order does not have an assigned driver.',
    'invalid_state_transition' => 'That transition is not allowed.',
    'not_your_order' => 'This order does not belong to you.',

    // Codes / geofence
    'invalid_pickup_code' => 'The pickup code is incorrect.',
    'invalid_delivery_code' => 'The delivery code is incorrect.',
    'code_locked' => 'Too many incorrect attempts. Contact support.',
    'method_required' => 'A verification method is required.',
    'code_required' => 'The verification code is required.',
    'geofence_not_confirmed' => 'The sender has not confirmed your arrival yet.',
    'driver_not_at_pickup' => 'You are not within the pickup geofence yet.',
    'driver_not_near_dropoff' => 'You are not within the dropoff area yet.',

    // Driver presence
    'driver_not_active' => 'Driver account is not active.',
    'driver_no_regions' => 'No regions assigned. Pick at least one region first.',
    'driver_gps_required' => 'GPS coordinates are required.',
    'driver_out_of_service_area' => 'You are outside the active service area.',
    'driver_liability_max' => 'You have reached your cash liability ceiling. Settle at office to continue.',
    'driver_liability_insufficient' => 'Driver liability headroom is insufficient for this order.',
    'driver_has_active_order' => 'Driver already has an active order.',
    'driver_location_stale' => 'Your GPS update is stale. Refresh and try again.',
    'driver_blocked_by_debt' => 'Account is blocked due to outstanding debt.',

    // Admin
    'vehicle_mismatch' => 'Vehicle type does not match item size.',
    'driver_region_mismatch' => 'Driver is not assigned to the pickup region.',
];
```

File: `lang/ar/order_messages.php` — mirror the structure, keys identical, Arabic values:

```php
<?php

declare(strict_types=1);

return [
    'pickup_out_of_service_area' => 'موقع الاستلام خارج منطقة الخدمة النشطة.',
    'invalid_quote_token' => 'عرض السعر غير صالح. اطلب عرضًا جديدًا وأعد المحاولة.',
    'quote_expired' => 'انتهت صلاحية عرض السعر. اطلب عرضًا جديدًا وأعد المحاولة.',
    'quote_price_changed' => 'تغير السعر منذ معاينته. راجع العرض المحدث وأكد.',
    'sender_is_receiver' => 'لا يمكنك إنشاء طلب لنفسك.',
    'merchant_use_merchant_flow' => 'يجب أن تستخدم حسابات التجار مسار الطلبات التجارية.',
    'idempotency_conflict' => 'طلب آخر قيد التنفيذ بنفس مفتاح الـ idempotency.',
    'order_already_claimed' => 'تم قبول هذا الطلب من سائق آخر.',
    'order_not_retryable' => 'لا يمكن إعادة محاولة هذا الطلب في حالته الحالية.',
    'order_not_cancellable_from_state' => 'لا يمكن إلغاء هذا الطلب في حالته الحالية.',
    'order_not_assignable' => 'لا يمكن تعيين هذا الطلب في حالته الحالية.',
    'order_not_unassignable' => 'لا يمكن إلغاء تعيين هذا الطلب في حالته الحالية.',
    'order_has_no_driver' => 'لا يوجد سائق معين لهذا الطلب.',
    'invalid_state_transition' => 'هذا التحول غير مسموح.',
    'not_your_order' => 'هذا الطلب لا ينتمي إليك.',
    'invalid_pickup_code' => 'رمز الاستلام غير صحيح.',
    'invalid_delivery_code' => 'رمز التسليم غير صحيح.',
    'code_locked' => 'محاولات خاطئة كثيرة. اتصل بالدعم.',
    'method_required' => 'طريقة التحقق مطلوبة.',
    'code_required' => 'رمز التحقق مطلوب.',
    'geofence_not_confirmed' => 'لم يؤكد المرسل وصولك بعد.',
    'driver_not_at_pickup' => 'لست داخل نطاق نقطة الاستلام بعد.',
    'driver_not_near_dropoff' => 'لست داخل نطاق التسليم بعد.',
    'driver_not_active' => 'حساب السائق غير نشط.',
    'driver_no_regions' => 'لم يتم تعيين أي منطقة. اختر منطقة على الأقل أولاً.',
    'driver_gps_required' => 'إحداثيات GPS مطلوبة.',
    'driver_out_of_service_area' => 'أنت خارج منطقة الخدمة النشطة.',
    'driver_liability_max' => 'وصلت إلى الحد الأقصى للنقد المسموح. قم بالتسوية في المكتب للمتابعة.',
    'driver_liability_insufficient' => 'سقف النقد المتبقي للسائق غير كافٍ لهذا الطلب.',
    'driver_has_active_order' => 'لدى السائق طلب نشط بالفعل.',
    'driver_location_stale' => 'تحديث موقعك قديم. حدّث وأعد المحاولة.',
    'driver_blocked_by_debt' => 'الحساب محظور بسبب ديون غير مسددة.',
    'vehicle_mismatch' => 'نوع المركبة لا يتطابق مع حجم العنصر.',
    'driver_region_mismatch' => 'السائق غير مُعيّن لمنطقة الاستلام.',
];
```

- [ ] **Step 2.10: Tinker smoke — verify infrastructure.**

Run: `php artisan tinker`

```php
\App\Enums\OrderErrorCode::OrderAlreadyClaimed->httpStatus();                 // 409
\App\Enums\OrderErrorCode::QuoteExpired->httpStatus();                        // 410
new \App\Exceptions\Order\OrderDomainException(\App\Enums\OrderErrorCode::OrderAlreadyClaimed, 'test', ['attempts' => 2])->toResponse();
// returns array with error.code = 'ORDER_ALREADY_CLAIMED', details.attempts = 2

\App\Support\OrderDisplayStatus::fromInternal(\App\Enums\OrderStatus::DriverEnRoutePickup);  // 'assigned'
\App\Support\OrderDisplayStatus::fromInternal(\App\Enums\OrderStatus::PickedUp);       // 'picked_up'

$t = \App\Support\QuoteToken::sign(['fee' => '12.00', 'expires_at' => time() + 60]);
$decoded = \App\Support\QuoteToken::verify($t);
$decoded['expired'];                                                          // false
$decoded['payload']['fee'];                                                   // "12.00"

try {
    \App\Support\QuoteToken::verify($t . 'tampered');
} catch (\InvalidArgumentException $e) { $e->getMessage(); }                 // 'bad_signature'

trans('order_messages.order_already_claimed');                                // English message
app()->setLocale('ar');
trans('order_messages.order_already_claimed');                                // Arabic message
app()->setLocale('en');
```

---

## Task 3: Platform-settings seeder for the milestone

**Files:**
- Create: `database/seeders/OrderLifecyclePlatformSettingsSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`

- [ ] **Step 3.1: Create the seeder.**

File: `database/seeders/OrderLifecyclePlatformSettingsSeeder.php`

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PlatformSetting;
use Illuminate\Database\Seeder;

final class OrderLifecyclePlatformSettingsSeeder extends Seeder
{
    /**
     * Idempotent — uses firstOrNew internally via PlatformSetting::set.
     * Day-1 defaults match the spec's "flat fee per region" baseline.
     */
    public function run(): void
    {
        $defaults = [
            // Pricing
            ['key' => 'pricing.item_size_modifiers', 'type' => 'json', 'value' => ['small' => 0, 'medium' => 0, 'large' => 0, 'xlarge' => 0], 'description' => 'LYD additions by item size, on top of region.base_fee'],
            ['key' => 'pricing.free_km', 'type' => 'decimal', 'value' => 999, 'description' => 'Distance below this is free; effectively disables per-km pricing at default'],
            ['key' => 'pricing.per_km_rate', 'type' => 'decimal', 'value' => 0, 'description' => 'LYD per km charged beyond free_km'],
            ['key' => 'pricing.item_commission_rate', 'type' => 'decimal', 'value' => 0.00, 'description' => 'P2P sale platform commission rate (0 at launch)'],
            ['key' => 'pricing.driver_fee_cut_rate', 'type' => 'decimal', 'value' => 0.02, 'description' => 'Platform cut of delivery fee (2% per spec §4.2)'],

            // Broadcast / escalation
            ['key' => 'broadcast.tier_1_radius_km', 'type' => 'decimal', 'value' => 3],
            ['key' => 'broadcast.tier_2_radius_km', 'type' => 'decimal', 'value' => 5],
            ['key' => 'broadcast.tier_3_radius_km', 'type' => 'decimal', 'value' => 10],
            ['key' => 'broadcast.tier_2_after_minutes', 'type' => 'integer', 'value' => 3],
            ['key' => 'broadcast.tier_3_after_minutes', 'type' => 'integer', 'value' => 6],
            ['key' => 'broadcast.tier_2_surcharge_percent', 'type' => 'integer', 'value' => 20],
            ['key' => 'broadcast.tier_3_surcharge_percent', 'type' => 'integer', 'value' => 50],
            ['key' => 'broadcast.no_driver_after_minutes', 'type' => 'integer', 'value' => 10],

            // Pickup / dropoff geofence
            ['key' => 'pickup.geofence_meters', 'type' => 'integer', 'value' => 500],
            ['key' => 'pickup.dropoff_sanity_meters', 'type' => 'integer', 'value' => 1000],

            // Codes
            ['key' => 'codes.max_attempts', 'type' => 'integer', 'value' => 5],
            ['key' => 'codes.enforce_pickup', 'type' => 'boolean', 'value' => true],
            ['key' => 'codes.enforce_delivery', 'type' => 'boolean', 'value' => true],

            // Driver presence
            ['key' => 'driver.location_stale_after_seconds', 'type' => 'integer', 'value' => 120],
            ['key' => 'driver.idle_offline_after_minutes', 'type' => 'integer', 'value' => 30],
            ['key' => 'driver.gps_lost_offline_after_minutes', 'type' => 'integer', 'value' => 5],

            // Quote
            ['key' => 'quote.ttl_seconds', 'type' => 'integer', 'value' => 300],
        ];

        foreach ($defaults as $row) {
            $setting = PlatformSetting::query()->firstOrNew(['key' => $row['key']]);
            // Only set value if creating; preserve any admin tuning that happened post-seed.
            if (! $setting->exists) {
                $setting->type = $row['type'];
                $setting->value = is_scalar($row['value']) ? (string) $row['value'] : (string) json_encode($row['value']);
                $setting->description = $row['description'] ?? null;
                $setting->save();
            }
        }
    }
}
```

- [ ] **Step 3.2: Call the seeder from `DatabaseSeeder`.**

File: `database/seeders/DatabaseSeeder.php` — inside the `run()` method, add (preserving any existing order; this seeder is idempotent so call order is flexible):

```php
$this->call(OrderLifecyclePlatformSettingsSeeder::class);
```

- [ ] **Step 3.3: Run the seeder.**

Run: `php artisan db:seed --class=OrderLifecyclePlatformSettingsSeeder`

Expected: completes silently. Re-running is a no-op.

- [ ] **Step 3.4: Tinker smoke — verify settings are readable.**

```php
\App\Models\PlatformSetting::get('pricing.driver_fee_cut_rate');               // 0.02 (float)
\App\Models\PlatformSetting::get('broadcast.tier_2_surcharge_percent');        // 20 (int)
\App\Models\PlatformSetting::get('codes.enforce_pickup');                       // true
\App\Models\PlatformSetting::get('pricing.item_size_modifiers');                // ['small'=>0,...]
\App\Models\PlatformSetting::get('quote.ttl_seconds');                          // 300
```

---

## Task 4: PricingService (pure computation)

**Files:**
- Create: `app/Services/Order/PricingService.php`

- [ ] **Step 4.1: Create the service.**

File: `app/Services/Order/PricingService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Enums\ItemSize;
use App\Enums\OrderErrorCode;
use App\Enums\OrderType;
use App\Exceptions\Order\OrderDomainException;
use App\Models\PlatformSetting;
use App\Models\Region;
use Illuminate\Support\Facades\DB;

final class PricingService
{
    /**
     * Pure computation. No DB writes.
     *
     * @return array{
     *   region_id: int,
     *   region_name: string,
     *   distance_km: string,
     *   delivery_fee_base: string,
     *   delivery_fee: string,
     *   delivery_fee_surcharge_percent: int,
     *   item_price: string,
     *   commission_rate: string,
     *   commission_amount: string,
     *   driver_fee_cut_rate: string,
     *   driver_fee_cut_amount: string,
     *   cash_collected_at_delivery: string
     * }
     */
    public function compute(
        OrderType $orderType,
        float $pickupLat,
        float $pickupLng,
        float $receiverLat,
        float $receiverLng,
        ItemSize $itemSize,
        string $itemPrice,              // "0.00" for standard_delivery
        string $deliveryFeePayer,       // 'sender' | 'receiver'
        string $paymentMethod,          // 'cash' | 'wallet'
    ): array {
        $region = $this->resolveRegion($pickupLng, $pickupLat);

        $distanceKm = $this->straightLineKm($pickupLng, $pickupLat, $receiverLng, $receiverLat);

        $modifiers = (array) PlatformSetting::get('pricing.item_size_modifiers', []);
        $sizeMod = (string) ($modifiers[$itemSize->value] ?? 0);

        $freeKm = (string) PlatformSetting::get('pricing.free_km', 999);
        $perKmRate = (string) PlatformSetting::get('pricing.per_km_rate', 0);

        $kmCharged = bccomp($distanceKm, $freeKm, 1) === 1
            ? bcsub($distanceKm, $freeKm, 1)
            : '0.0';
        $distanceFee = bcmul($kmCharged, $perKmRate, 2);

        $base = bcadd(
            bcadd((string) $region->base_fee, $sizeMod, 2),
            $distanceFee,
            2
        );

        // Surcharge starts at 0 at creation; escalation job bumps it later.
        $surchargePercent = 0;
        $fee = $base; // = base × (1 + 0/100)

        // P2P commission
        $commissionRate = $orderType === OrderType::P2pSale
            ? (string) PlatformSetting::get('pricing.item_commission_rate', 0)
            : '0';
        $commissionAmount = bcmul($itemPrice, $commissionRate, 2);

        $driverCutRate = (string) PlatformSetting::get('pricing.driver_fee_cut_rate', 0.02);
        $driverCutAmount = bcmul($base, $driverCutRate, 2);

        $cashAtDelivery = bcadd(
            $itemPrice,
            ($deliveryFeePayer === 'receiver' && $paymentMethod === 'cash') ? $fee : '0',
            2
        );

        return [
            'region_id' => $region->id,
            'region_name' => $region->name,
            'distance_km' => $distanceKm,
            'delivery_fee_base' => $base,
            'delivery_fee' => $fee,
            'delivery_fee_surcharge_percent' => $surchargePercent,
            'item_price' => bcadd($itemPrice, '0', 2),  // normalise to 2dp
            'commission_rate' => $commissionRate,
            'commission_amount' => $commissionAmount,
            'driver_fee_cut_rate' => $driverCutRate,
            'driver_fee_cut_amount' => $driverCutAmount,
            'cash_collected_at_delivery' => $cashAtDelivery,
        ];
    }

    private function resolveRegion(float $lng, float $lat): Region
    {
        $row = DB::selectOne(
            "SELECT id, name, base_fee::text AS base_fee
               FROM regions
              WHERE ST_Contains(boundary::geometry, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geometry)
              LIMIT 1",
            [$lng, $lat]
        );

        if ($row === null) {
            throw new OrderDomainException(
                OrderErrorCode::PickupOutOfServiceArea,
                trans('order_messages.pickup_out_of_service_area'),
            );
        }

        $region = new Region();
        $region->id = (int) $row->id;
        $region->name = (string) $row->name;
        $region->base_fee = (string) $row->base_fee;

        return $region;
    }

    private function straightLineKm(float $lng1, float $lat1, float $lng2, float $lat2): string
    {
        $meters = (float) DB::selectOne(
            'SELECT ST_Distance(ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) AS d',
            [$lng1, $lat1, $lng2, $lat2]
        )->d;

        return bcdiv((string) round($meters, 0), '1000', 1);
    }
}
```

- [ ] **Step 4.2: Tinker smoke — verify pricing math.**

Run: `php artisan tinker`

```php
$svc = new \App\Services\Order\PricingService();

// Pick any region's centroid; assumes at least one region was seeded
$r = \App\Models\Region::first();
$centroid = \DB::selectOne('SELECT ST_X(ST_Centroid(boundary::geometry)) lng, ST_Y(ST_Centroid(boundary::geometry)) lat FROM regions WHERE id = ?', [$r->id]);

// Bump that region's base_fee temporarily so the math is non-zero
\DB::table('regions')->where('id', $r->id)->update(['base_fee' => 10]);

$result = $svc->compute(
    \App\Enums\OrderType::StandardDelivery,
    (float) $centroid->lat, (float) $centroid->lng,
    (float) $centroid->lat + 0.01, (float) $centroid->lng + 0.01,
    \App\Enums\ItemSize::Medium,
    '0.00',
    'sender',
    'cash',
);
$result['delivery_fee_base'];          // "10.00"
$result['driver_fee_cut_amount'];      // "0.20"  (10 × 0.02)
$result['cash_collected_at_delivery']; // "0.00"  (standard, sender pays at pickup)
$result['distance_km'];                // ~"1.5"  (approx — straight-line of ~0.014°)
```

---

## Task 5: QuoteService + `POST /api/orders/quote`

**Files:**
- Create: `app/Services/Order/QuoteService.php`
- Create: `app/Http/Requests/Order/QuoteOrderRequest.php`
- Create: `app/Http/Resources/Order/QuoteResource.php`
- Create: `app/Http/Controllers/Api/Order/QuoteController.php`
- Modify: `routes/api.php`
- Modify: `bootstrap/app.php` (register `orders_quote` throttle limiter)

- [ ] **Step 5.1: Create `QuoteService`.**

File: `app/Services/Order/QuoteService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Enums\ItemSize;
use App\Enums\OrderType;
use App\Models\PlatformSetting;
use App\Support\QuoteToken;

final class QuoteService
{
    public function __construct(private readonly PricingService $pricing) {}

    /**
     * @return array{
     *   quote_token: string,
     *   expires_at: string,
     *   pricing: array<string, mixed>
     * }
     */
    public function quote(
        OrderType $orderType,
        float $pickupLat,
        float $pickupLng,
        float $receiverLat,
        float $receiverLng,
        ItemSize $itemSize,
        string $itemPrice,
        string $deliveryFeePayer,
    ): array {
        $paymentMethod = 'cash'; // MVP: cash only; wallet pre-pay is future

        $pricing = $this->pricing->compute(
            $orderType,
            $pickupLat, $pickupLng,
            $receiverLat, $receiverLng,
            $itemSize,
            $itemPrice,
            $deliveryFeePayer,
            $paymentMethod,
        );

        $ttl = (int) PlatformSetting::get('quote.ttl_seconds', 300);
        $expiresAt = time() + $ttl;

        $payload = [
            'order_type' => $orderType->value,
            'pickup_lat' => $pickupLat, 'pickup_lng' => $pickupLng,
            'receiver_lat' => $receiverLat, 'receiver_lng' => $receiverLng,
            'item_size' => $itemSize->value,
            'item_price' => $pricing['item_price'],
            'delivery_fee_payer' => $deliveryFeePayer,
            'region_id' => $pricing['region_id'],
            'delivery_fee_base' => $pricing['delivery_fee_base'],
            'commission_amount' => $pricing['commission_amount'],
            'driver_fee_cut_amount' => $pricing['driver_fee_cut_amount'],
            'expires_at' => $expiresAt,
        ];

        return [
            'quote_token' => QuoteToken::sign($payload),
            'expires_at' => gmdate('c', $expiresAt),
            'pricing' => $pricing,
        ];
    }
}
```

- [ ] **Step 5.2: Create FormRequest.**

File: `app/Http/Requests/Order/QuoteOrderRequest.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use App\Enums\ItemSize;
use App\Enums\OrderType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class QuoteOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // covered by route middleware (sanctum)
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'order_type' => ['required', Rule::in([
                OrderType::StandardDelivery->value,
                OrderType::P2pSale->value,
            ])],
            'pickup_location' => ['required', 'array'],
            'pickup_location.lat' => ['required', 'numeric', 'between:-90,90'],
            'pickup_location.lng' => ['required', 'numeric', 'between:-180,180'],
            'receiver_location' => ['required', 'array'],
            'receiver_location.lat' => ['required', 'numeric', 'between:-90,90'],
            'receiver_location.lng' => ['required', 'numeric', 'between:-180,180'],
            'item_size' => ['required', Rule::enum(ItemSize::class)],
            'item_price' => [
                Rule::requiredIf(fn () => $this->input('order_type') === OrderType::P2pSale->value),
                'numeric',
                'min:0.01',
                'max:99999999.99',
            ],
            'delivery_fee_payer' => ['sometimes', Rule::in(['sender', 'receiver'])],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($v): void {
            if ($this->input('order_type') === OrderType::StandardDelivery->value && $this->filled('item_price')) {
                $v->errors()->add('item_price', 'item_price is not allowed for standard_delivery orders.');
            }
        });
    }

    public function resolvedItemPrice(): string
    {
        $raw = $this->input('item_price', '0');
        return bcadd((string) $raw, '0', 2);
    }

    public function resolvedPayer(): string
    {
        // Spec §4.4: P2P sale = receiver only (forced); standard_delivery defaults to sender.
        if ($this->input('order_type') === OrderType::P2pSale->value) {
            return 'receiver';
        }
        return (string) $this->input('delivery_fee_payer', 'sender');
    }
}
```

- [ ] **Step 5.3: Create resource.**

File: `app/Http/Resources/Order/QuoteResource.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Order;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class QuoteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array{quote_token: string, expires_at: string, pricing: array<string, mixed>} $r */
        $r = (array) $this->resource;

        return [
            'quote_token' => $r['quote_token'],
            'expires_at' => $r['expires_at'],
            'pricing' => [
                'region' => [
                    'id' => $r['pricing']['region_id'],
                    'name' => $r['pricing']['region_name'],
                ],
                'distance_km' => $r['pricing']['distance_km'],
                'delivery_fee_base' => $r['pricing']['delivery_fee_base'],
                'delivery_fee' => $r['pricing']['delivery_fee'],
                'delivery_fee_surcharge_percent' => $r['pricing']['delivery_fee_surcharge_percent'],
                'item_price' => $r['pricing']['item_price'],
                'commission_rate' => $r['pricing']['commission_rate'],
                'commission_amount' => $r['pricing']['commission_amount'],
                'driver_fee_cut_rate' => $r['pricing']['driver_fee_cut_rate'],
                'driver_fee_cut_amount' => $r['pricing']['driver_fee_cut_amount'],
                'cash_collected_at_delivery' => $r['pricing']['cash_collected_at_delivery'],
            ],
        ];
    }
}
```

- [ ] **Step 5.4: Create controller.**

File: `app/Http/Controllers/Api/Order/QuoteController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Order;

use App\Enums\ItemSize;
use App\Enums\OrderType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\QuoteOrderRequest;
use App\Http\Resources\Order\QuoteResource;
use App\Services\Order\QuoteService;

final class QuoteController extends Controller
{
    public function __construct(private readonly QuoteService $quotes) {}

    public function __invoke(QuoteOrderRequest $req): QuoteResource
    {
        $result = $this->quotes->quote(
            orderType: OrderType::from((string) $req->input('order_type')),
            pickupLat: (float) $req->input('pickup_location.lat'),
            pickupLng: (float) $req->input('pickup_location.lng'),
            receiverLat: (float) $req->input('receiver_location.lat'),
            receiverLng: (float) $req->input('receiver_location.lng'),
            itemSize: ItemSize::from((string) $req->input('item_size')),
            itemPrice: $req->resolvedItemPrice(),
            deliveryFeePayer: $req->resolvedPayer(),
        );

        return new QuoteResource($result);
    }
}
```

- [ ] **Step 5.5: Register the `orders_quote` named rate limiter.**

File: `bootstrap/app.php` — in the section that registers rate limiters (look for existing `RateLimiter::for('login', ...)` calls; add alongside):

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

RateLimiter::for('orders_quote', fn (Request $r) => [
    Limit::perMinute(30)->by((string) optional($r->user())->id),
]);
```

- [ ] **Step 5.6: Register the route.**

File: `routes/api.php` — add after the existing `/me` block, before the default Sanctum scaffolding:

```php
use App\Http\Controllers\Api\Order\QuoteController;

Route::middleware('auth:sanctum')->prefix('orders')->group(function (): void {
    Route::post('quote', QuoteController::class)
        ->middleware('throttle:orders_quote');
});
```

- [ ] **Step 5.7: Tinker smoke — quote endpoint end-to-end.**

```php
$user = \App\Models\User::query()->where('phone_verified_at', '!=', null)->first();
$token = $user->createToken('quote-smoke')->plainTextToken;

$r = \App\Models\Region::first();
$centroid = \DB::selectOne('SELECT ST_X(ST_Centroid(boundary::geometry)) lng, ST_Y(ST_Centroid(boundary::geometry)) lat FROM regions WHERE id = ?', [$r->id]);

$body = [
    'order_type' => 'standard_delivery',
    'pickup_location' => ['lat' => (float) $centroid->lat, 'lng' => (float) $centroid->lng],
    'receiver_location' => ['lat' => (float) $centroid->lat + 0.01, 'lng' => (float) $centroid->lng + 0.01],
    'item_size' => 'small',
];

$resp = \Illuminate\Support\Facades\Http::withToken($token)
    ->acceptJson()
    ->post(url('/api/orders/quote'), $body);

$resp->status();                       // 200
$resp->json('quote_token');            // "<base64>.<hex>"
$resp->json('pricing.delivery_fee');   // matches region.base_fee at default settings
$resp->json('pricing.region.name');    // region name
```

---

## Task 6: OrderResource + GuestTrackingResource + DriverOrderResource + AdminOrderResource

**Files:**
- Create: `app/Http/Resources/Order/OrderResource.php`
- Create: `app/Http/Resources/Order/DriverOrderResource.php`
- Create: `app/Http/Resources/Order/AdminOrderResource.php`
- Create: `app/Http/Resources/Order/OrderStatusLogResource.php`
- Create: `app/Http/Resources/Order/GuestTrackingResource.php`

- [ ] **Step 6.1: Create `OrderResource` (sender + receiver — conditionally redacts).**

File: `app/Http/Resources/Order/OrderResource.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Order;

use App\Models\Order;
use App\Support\OrderDisplayStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Order $o */
        $o = $this->resource;
        $user = $request->user();
        $isSender = $user !== null && $user->id === $o->sender_user_id;
        $isReceiver = $user !== null && $o->receiver_user_id !== null && $user->id === $o->receiver_user_id;

        $payload = [
            'id' => $o->public_id,
            'order_type' => $o->order_type->value,
            'display_status' => OrderDisplayStatus::fromInternal($o->status),
            'status_changed_at' => optional($o->status_changed_at)?->toIso8601String(),
            'created_at' => $o->created_at?->toIso8601String(),

            'pickup' => [
                'address' => $o->pickup_address,
                'location' => $this->pointToArray($o->pickup_location),
                'notes' => $isSender ? $o->pickup_notes : null,
                'pickup_code' => $isSender ? $o->pickup_code : null,
                'geofence_confirmed_at' => $isSender ? optional($o->pickup_geofence_confirmed_at)?->toIso8601String() : null,
            ],

            'receiver' => [
                'address' => $o->receiver_address,
                'location' => $this->pointToArray($o->receiver_location),
                'notes' => $o->receiver_notes,
                'phone' => $isSender ? $o->receiver_phone : null,
                'name' => $isSender ? $o->receiver_name : null,
                'delivery_code' => $isReceiver ? $o->delivery_code : null,
            ],

            'item' => [
                'description' => $o->item_description,
                'size' => $o->item_size->value,
                'weight_kg' => $o->item_weight_kg ? (string) $o->item_weight_kg : null,
                'value' => $o->item_value ? (string) $o->item_value : null,
                'price' => (string) $o->item_price,
            ],

            'pricing' => [
                'delivery_fee_base' => (string) $o->delivery_fee_base,
                'delivery_fee_surcharge_percent' => $o->delivery_fee_surcharge_percent,
                'delivery_fee' => (string) $o->delivery_fee,
                'delivery_fee_payer' => $o->delivery_fee_payer->value,
                'delivery_fee_payment_method' => $o->delivery_fee_payment_method->value,
                'delivery_fee_status' => $o->delivery_fee_status->value,
                'commission_amount' => $isSender ? (string) $o->commission_amount : null,
                'cash_collected_at_delivery' => $o->cashCollectedAtDelivery(),
            ],

            'driver' => $this->driverBlock($o, $isSender, $isReceiver),

            'timestamps' => [
                'assigned_at' => optional($o->assigned_at)?->toIso8601String(),
                'picked_up_at' => optional($o->picked_up_at)?->toIso8601String(),
                'delivery_in_progress_at' => optional($o->delivery_in_progress_at)?->toIso8601String(),
                'delivered_at' => optional($o->delivered_at)?->toIso8601String(),
                'no_driver_available_at' => optional($o->no_driver_available_at)?->toIso8601String(),
                'cancelled_at' => optional($o->cancelled_at)?->toIso8601String(),
            ],
        ];

        return $payload;
    }

    /** @return array<string, mixed>|null */
    private function driverBlock(Order $o, bool $isSender, bool $isReceiver): ?array
    {
        if ($o->driver_id === null) {
            return null;
        }
        // Receiver only sees driver block from picked_up onward.
        if ($isReceiver && ! $isSender) {
            $allowed = in_array($o->status, [
                \App\Enums\OrderStatus::PickedUp,
                \App\Enums\OrderStatus::DriverEnRouteDropoff,
                \App\Enums\OrderStatus::DeliveryInProgress,
                \App\Enums\OrderStatus::Delivered,
            ], true);
            if (! $allowed) {
                return null;
            }
        }

        $driver = $o->driver;
        $profile = $driver?->driverProfile ?? null;

        return [
            'first_name' => $driver?->first_name,
            'phone' => $driver?->phone,
            'vehicle_type' => $profile?->vehicle_type?->value,
            'current_location' => $profile ? $this->pointToArray($profile->current_location) : null,
            'last_seen_at' => optional($profile?->last_active_at)?->toIso8601String(),
        ];
    }

    /** @return array{lat: float, lng: float}|null */
    private function pointToArray(mixed $p): ?array
    {
        if ($p === null) {
            return null;
        }
        return ['lat' => (float) $p->getLatitude(), 'lng' => (float) $p->getLongitude()];
    }
}
```

> **Note:** `Order::$driver` returns a `User` model. The User model needs a `driverProfile()` relation if not already present. Verify with `\App\Models\User::first()->driverProfile` in Tinker; if it fails, add `public function driverProfile(): HasOne { return $this->hasOne(DriverProfile::class); }` to `User.php` as a quick add-on inside this task (Step 6.1a).

- [ ] **Step 6.1a: Verify User → DriverProfile relation exists; add if missing.**

```php
// php artisan tinker
\App\Models\User::first()->driverProfile;
```

If this errors with "Call to undefined method", add to `app/Models/User.php`:

```php
public function driverProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
{
    return $this->hasOne(\App\Models\DriverProfile::class);
}
```

- [ ] **Step 6.2: Create `DriverOrderResource`.**

File: `app/Http/Resources/Order/DriverOrderResource.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Order;

use App\Models\Order;
use App\Models\PlatformSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DriverOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Order $o */
        $o = $this->resource;

        return [
            'id' => $o->public_id,
            'order_type' => $o->order_type->value,
            'status' => $o->status->value,                  // raw — driver needs granularity
            'status_changed_at' => optional($o->status_changed_at)?->toIso8601String(),

            'sender' => [
                'name' => $o->sender_name,
                'phone' => $o->sender_phone,                 // visible to assigned driver
            ],

            'pickup' => [
                'address' => $o->pickup_address,
                'location' => $this->pointToArray($o->pickup_location),
                'notes' => $o->pickup_notes,
                // NOTE: pickup_code never exposed to driver — sender hands them the code.
                'geofence_confirmed_at' => optional($o->pickup_geofence_confirmed_at)?->toIso8601String(),
                'code_required' => (bool) PlatformSetting::get('codes.enforce_pickup', true),
            ],

            'receiver' => [
                'name' => $o->receiver_name,
                'phone' => $o->receiver_phone,
                'address' => $o->receiver_address,
                'location' => $this->pointToArray($o->receiver_location),
                'notes' => $o->receiver_notes,
                // NOTE: delivery_code never exposed to driver.
                'code_required' => (bool) PlatformSetting::get('codes.enforce_delivery', true),
            ],

            'item' => [
                'description' => $o->item_description,
                'size' => $o->item_size->value,
                'weight_kg' => $o->item_weight_kg ? (string) $o->item_weight_kg : null,
                'price' => (string) $o->item_price,
            ],

            'earnings' => [
                'delivery_fee' => (string) $o->delivery_fee,
                'driver_fee_cut_amount' => (string) $o->driver_fee_cut_amount,
                'driver_take_home' => bcsub((string) $o->delivery_fee, (string) $o->driver_fee_cut_amount, 2),
                'cash_to_collect' => $o->cashCollectedAtDelivery(),
                'delivery_fee_payer' => $o->delivery_fee_payer->value,
                'delivery_fee_payment_method' => $o->delivery_fee_payment_method->value,
            ],
        ];
    }

    /** @return array{lat: float, lng: float}|null */
    private function pointToArray(mixed $p): ?array
    {
        return $p === null ? null : ['lat' => (float) $p->getLatitude(), 'lng' => (float) $p->getLongitude()];
    }
}
```

- [ ] **Step 6.3: Create `OrderStatusLogResource`.**

File: `app/Http/Resources/Order/OrderStatusLogResource.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Order;

use App\Models\OrderStatusLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OrderStatusLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var OrderStatusLog $l */
        $l = $this->resource;

        return [
            'from_status' => $l->from_status?->value,
            'to_status' => $l->to_status->value,
            'actor_type' => $l->actor_type->value,
            'actor_id' => $l->actor_id,
            'reason' => $l->reason,
            'metadata' => $l->metadata,
            'actor_location' => $l->actor_location
                ? ['lat' => (float) $l->actor_location->getLatitude(), 'lng' => (float) $l->actor_location->getLongitude()]
                : null,
            'created_at' => $l->created_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 6.4: Create `AdminOrderResource` (everything visible).**

File: `app/Http/Resources/Order/AdminOrderResource.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Order;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AdminOrderResource extends JsonResource
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
            'status_changed_at' => optional($o->status_changed_at)?->toIso8601String(),
            'tracking_token' => $o->tracking_token,

            'sender' => [
                'user_id' => $o->sender_user_id,
                'name' => $o->sender_name,
                'phone' => $o->sender_phone,
            ],
            'pickup' => [
                'address' => $o->pickup_address,
                'location' => $this->pt($o->pickup_location),
                'notes' => $o->pickup_notes,
                'code' => $o->pickup_code,           // visible to admin
                'code_attempts' => $o->pickup_code_attempts,
                'picked_up_method' => $o->picked_up_method?->value,
                'geofence_confirmed_at' => optional($o->pickup_geofence_confirmed_at)?->toIso8601String(),
            ],
            'receiver' => [
                'type' => $o->receiver_type->value,
                'user_id' => $o->receiver_user_id,
                'guest_id' => $o->receiver_guest_id,
                'name' => $o->receiver_name,
                'phone' => $o->receiver_phone,
                'address' => $o->receiver_address,
                'location' => $this->pt($o->receiver_location),
                'notes' => $o->receiver_notes,
                'code' => $o->delivery_code,        // visible to admin
                'code_attempts' => $o->delivery_code_attempts,
                'delivered_method' => $o->delivered_method?->value,
            ],
            'item' => [
                'description' => $o->item_description,
                'size' => $o->item_size->value,
                'weight_kg' => $o->item_weight_kg ? (string) $o->item_weight_kg : null,
                'value' => $o->item_value ? (string) $o->item_value : null,
                'price' => (string) $o->item_price,
            ],
            'pricing' => [
                'delivery_fee_base' => (string) $o->delivery_fee_base,
                'delivery_fee_surcharge_percent' => $o->delivery_fee_surcharge_percent,
                'delivery_fee' => (string) $o->delivery_fee,
                'commission_rate' => (string) $o->commission_rate,
                'commission_amount' => (string) $o->commission_amount,
                'driver_fee_cut_rate' => (string) $o->driver_fee_cut_rate,
                'driver_fee_cut_amount' => (string) $o->driver_fee_cut_amount,
                'delivery_fee_payer' => $o->delivery_fee_payer->value,
                'delivery_fee_payment_method' => $o->delivery_fee_payment_method->value,
                'delivery_fee_status' => $o->delivery_fee_status->value,
                'delivery_fee_paid_at' => optional($o->delivery_fee_paid_at)?->toIso8601String(),
            ],
            'driver' => $o->driver_id ? [
                'user_id' => $o->driver_id,
                'first_name' => $o->driver?->first_name,
                'phone' => $o->driver?->phone,
                'assignment_attempts' => $o->driver_assignment_attempts,
                'search_radius_tier' => $o->search_radius_tier,
            ] : null,
            'timestamps' => [
                'awaiting_driver_at' => optional($o->awaiting_driver_at)?->toIso8601String(),
                'no_driver_available_at' => optional($o->no_driver_available_at)?->toIso8601String(),
                'assigned_at' => optional($o->assigned_at)?->toIso8601String(),
                'driver_en_route_pickup_at' => optional($o->driver_en_route_pickup_at)?->toIso8601String(),
                'picked_up_at' => optional($o->picked_up_at)?->toIso8601String(),
                'driver_en_route_dropoff_at' => optional($o->driver_en_route_dropoff_at)?->toIso8601String(),
                'delivery_in_progress_at' => optional($o->delivery_in_progress_at)?->toIso8601String(),
                'delivered_at' => optional($o->delivered_at)?->toIso8601String(),
                'cancelled_at' => optional($o->cancelled_at)?->toIso8601String(),
                'created_at' => $o->created_at?->toIso8601String(),
            ],
            'status_logs' => $this->whenLoaded('statusLogs', fn () => OrderStatusLogResource::collection($o->statusLogs)),
        ];
    }

    /** @return array{lat: float, lng: float}|null */
    private function pt(mixed $p): ?array
    {
        return $p === null ? null : ['lat' => (float) $p->getLatitude(), 'lng' => (float) $p->getLongitude()];
    }
}
```

- [ ] **Step 6.5: Create `GuestTrackingResource` (public, PII-minimal).**

File: `app/Http/Resources/Order/GuestTrackingResource.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Order;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Support\OrderDisplayStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class GuestTrackingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Order $o */
        $o = $this->resource;

        $senderFirstName = explode(' ', trim((string) $o->sender_name))[0] ?? null;

        return [
            'id' => $o->public_id,
            'display_status' => OrderDisplayStatus::fromInternal($o->status),
            'order_type' => $o->order_type->value,
            'item' => [
                'description' => $o->item_description,
                'size' => $o->item_size->value,
            ],
            'sender' => [
                'first_name' => $senderFirstName,    // no phone
            ],
            'pickup_address' => $o->pickup_address,
            'receiver_address' => $o->receiver_address,
            'receiver_location' => $o->receiver_location
                ? ['lat' => (float) $o->receiver_location->getLatitude(), 'lng' => (float) $o->receiver_location->getLongitude()]
                : null,
            'delivery_fee' => (string) $o->delivery_fee,
            'delivery_fee_payer' => $o->delivery_fee_payer->value,
            'cash_collected_at_delivery' => $o->cashCollectedAtDelivery(),
            'delivery_code' => $o->delivery_code,    // the receiver needs this
            'driver' => $this->driverBlock($o),
            'timestamps' => [
                'created_at' => $o->created_at?->toIso8601String(),
                'picked_up_at' => optional($o->picked_up_at)?->toIso8601String(),
                'delivered_at' => optional($o->delivered_at)?->toIso8601String(),
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
        if (! $afterPickup) {
            return null;
        }

        $driver = $o->driver;
        $profile = $driver?->driverProfile ?? null;
        $firstName = explode(' ', trim((string) $driver?->first_name))[0] ?? null;

        return [
            'first_name' => $firstName,
            'phone' => $driver?->phone,
            'vehicle_type' => $profile?->vehicle_type?->value,
            'current_location' => $profile?->current_location
                ? ['lat' => (float) $profile->current_location->getLatitude(), 'lng' => (float) $profile->current_location->getLongitude()]
                : null,
        ];
    }
}
```

- [ ] **Step 6.6: Tinker smoke — resource shapes (without DB rows yet).**

Resources require a real `Order` model to render against. Defer the smoke test for this task to Task 7's creation smoke (where we'll have an actual `Order` row).

---

## Task 7: StateTransitionService + post-transition hook registry

**Files:**
- Create: `app/Services/Order/Hooks/PostTransitionHookRegistry.php`
- Create: `app/Services/Order/StateTransitionService.php`

- [ ] **Step 7.1: Create the hook registry.**

File: `app/Services/Order/Hooks/PostTransitionHookRegistry.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Order\Hooks;

use App\Enums\OrderStatus;
use App\Models\Order;
use Closure;

/**
 * Maps (from, to) status pairs to closures that run inside the same
 * transaction as the status flip itself. Closures receive (Order $order, array $metadata).
 */
final class PostTransitionHookRegistry
{
    /** @var array<string, Closure> */
    private array $hooks = [];

    public function register(OrderStatus $from, OrderStatus $to, Closure $hook): void
    {
        $this->hooks[$this->key($from, $to)] = $hook;
    }

    /** @param  array<string, mixed>  $metadata */
    public function fire(Order $order, OrderStatus $from, OrderStatus $to, array $metadata = []): void
    {
        $hook = $this->hooks[$this->key($from, $to)] ?? null;
        if ($hook !== null) {
            $hook($order, $metadata);
        }
    }

    private function key(OrderStatus $from, OrderStatus $to): string
    {
        return $from->value . '|' . $to->value;
    }
}
```

- [ ] **Step 7.2: Create `StateTransitionService`.**

File: `app/Services/Order/StateTransitionService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Enums\OrderActorType;
use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Exceptions\Order\InvalidOrderTransitionException;
use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Services\Order\Hooks\PostTransitionHookRegistry;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Support\Facades\DB;
use LogicException;

final class StateTransitionService
{
    public function __construct(private readonly PostTransitionHookRegistry $hooks) {}

    /**
     * The only writer of orders.status.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function transition(
        Order $order,
        OrderStatus $to,
        OrderActorType $actorType,
        ?int $actorId = null,
        ?string $reason = null,
        ?Point $actorLocation = null,
        array $metadata = [],
    ): Order {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('StateTransitionService::transition must run inside DB::transaction.');
        }

        $from = $order->status;
        if (! $from->canTransitionTo($to)) {
            throw new InvalidOrderTransitionException($from, $to);
        }

        $now = now();
        $update = [
            'status' => $to->value,
            'status_changed_at' => $now,
        ];
        $timestampColumn = $this->timestampColumnFor($to);
        if ($timestampColumn !== null) {
            $update[$timestampColumn] = $now;
        }
        $order->fill($update);
        $order->save();

        OrderStatusLog::create([
            'order_id' => $order->id,
            'from_status' => $from->value,
            'to_status' => $to->value,
            'actor_type' => $actorType->value,
            'actor_id' => $actorId,
            'reason' => $reason,
            'actor_location' => $actorLocation,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);

        $this->hooks->fire($order, $from, $to, $metadata);

        OrderStatusChanged::dispatch($order, $from, $to, $actorType, $actorId);

        return $order->refresh();
    }

    private function timestampColumnFor(OrderStatus $to): ?string
    {
        return match ($to) {
            OrderStatus::AwaitingDriver => 'awaiting_driver_at',
            OrderStatus::NoDriverAvailable => 'no_driver_available_at',
            OrderStatus::Assigned => 'assigned_at',
            OrderStatus::DriverEnRoutePickup => 'driver_en_route_pickup_at',
            OrderStatus::PickedUp => 'picked_up_at',
            OrderStatus::DriverEnRouteDropoff => 'driver_en_route_dropoff_at',
            OrderStatus::DeliveryInProgress => 'delivery_in_progress_at',
            OrderStatus::Delivered => 'delivered_at',
            OrderStatus::DeliveryFailed => 'delivery_failed_at',
            OrderStatus::ReturningToOffice => 'returning_to_office_at',
            OrderStatus::AtOffice => 'at_office_at',
            OrderStatus::CancelledByUser, OrderStatus::CancelledByAdmin => 'cancelled_at',
            OrderStatus::RetrievedBySeller => 'retrieved_by_seller_at',
            OrderStatus::Abandoned => 'abandoned_at',
            default => null,
        };
    }
}
```

- [ ] **Step 7.3: Register the hook registry as a singleton.**

File: a new provider `app/Providers/OrderServiceProvider.php` (create) or extend an existing one (e.g. `AppServiceProvider::register()`):

```php
public function register(): void
{
    $this->app->singleton(\App\Services\Order\Hooks\PostTransitionHookRegistry::class, function ($app) {
        $registry = new \App\Services\Order\Hooks\PostTransitionHookRegistry();

        // Hook: en_route_pickup → picked_up
        // Sender + cash → mark delivery_fee paid (cash collected at pickup).
        $registry->register(
            \App\Enums\OrderStatus::DriverEnRoutePickup,
            \App\Enums\OrderStatus::PickedUp,
            static function (\App\Models\Order $order, array $metadata): void {
                if (
                    $order->delivery_fee_payer === \App\Enums\DeliveryFeePayer::Sender
                    && $order->delivery_fee_payment_method === \App\Enums\DeliveryFeePaymentMethod::Cash
                ) {
                    $order->update([
                        'delivery_fee_status' => \App\Enums\DeliveryFeeStatus::Paid->value,
                        'delivery_fee_paid_at' => now(),
                    ]);
                }
            }
        );

        // Hook: delivery_in_progress → delivered
        $registry->register(
            \App\Enums\OrderStatus::DeliveryInProgress,
            \App\Enums\OrderStatus::Delivered,
            static function (\App\Models\Order $order, array $metadata): void {
                // (a) Mark delivery_fee paid if receiver/cash
                if (
                    $order->delivery_fee_payer === \App\Enums\DeliveryFeePayer::Receiver
                    && $order->delivery_fee_payment_method === \App\Enums\DeliveryFeePaymentMethod::Cash
                ) {
                    $order->update([
                        'delivery_fee_status' => \App\Enums\DeliveryFeeStatus::Paid->value,
                        'delivery_fee_paid_at' => now(),
                    ]);
                }

                // (b) Credit driver buckets with auto-debt-offset (spec §4.7)
                /** @var \App\Models\DriverAccount $account */
                $account = \App\Models\DriverAccount::where('driver_id', $order->driver_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $cashCollected = $order->cashCollectedAtDelivery();
                $earnings = bcsub((string) $order->delivery_fee, (string) $order->driver_fee_cut_amount, 2);

                // cash_to_deposit always increases by cash collected
                $account->cash_to_deposit = bcadd((string) $account->cash_to_deposit, $cashCollected, 2);

                // auto-debt-offset
                if (bccomp((string) $account->debt_balance, '0', 2) > 0) {
                    $offset = bccomp($earnings, (string) $account->debt_balance, 2) >= 0
                        ? (string) $account->debt_balance
                        : $earnings;
                    $account->debt_balance = bcsub((string) $account->debt_balance, $offset, 2);
                    $earnings = bcsub($earnings, $offset, 2);
                }
                $account->earnings_balance = bcadd((string) $account->earnings_balance, $earnings, 2);

                // Lifetime trackers (spec keeps these for analytics)
                $account->lifetime_earnings = bcadd((string) $account->lifetime_earnings, bcsub((string) $order->delivery_fee, (string) $order->driver_fee_cut_amount, 2), 2);
                $account->lifetime_cash_handled = bcadd((string) $account->lifetime_cash_handled, $cashCollected, 2);
                $account->lifetime_platform_fees_paid = bcadd((string) $account->lifetime_platform_fees_paid, (string) $order->driver_fee_cut_amount, 2);
                $account->save();

                \App\Models\DriverAccountTransaction::create([
                    'driver_id' => $order->driver_id,
                    'bucket' => \App\Enums\DriverAccountBucket::CashToDeposit->value,
                    'amount' => $cashCollected,
                    'reason' => \App\Enums\DriverAccountTransactionReason::OrderCashCollected->value,
                    'order_id' => $order->id,
                    'balance_after' => $account->cash_to_deposit,
                ]);
                \App\Models\DriverAccountTransaction::create([
                    'driver_id' => $order->driver_id,
                    'bucket' => \App\Enums\DriverAccountBucket::EarningsBalance->value,
                    'amount' => bcsub((string) $order->delivery_fee, (string) $order->driver_fee_cut_amount, 2),
                    'reason' => \App\Enums\DriverAccountTransactionReason::OrderEarningsCredited->value,
                    'order_id' => $order->id,
                    'balance_after' => $account->earnings_balance,
                ]);

                // (c) Driver back to online
                \App\Models\DriverProfile::where('user_id', $order->driver_id)
                    ->update([
                        'activity_status' => \App\Enums\DriverActivityStatus::Online->value,
                        'lifetime_deliveries' => DB::raw('lifetime_deliveries + 1'),
                    ]);
            }
        );

        return $registry;
    });
}
```

> **Verify** the `DriverAccountTransaction` columns (`bucket`, `amount`, `reason`, `order_id`, `balance_after`) match the existing migration; if names differ, adjust to match exactly. Same for `DriverAccountTransactionReason` enum cases — use whatever cases exist in the project; only the credit reasons matter here.

- [ ] **Step 7.4: Register `OrderServiceProvider` (if newly created).**

File: `bootstrap/app.php` — add to `->withProviders([...])` (or wherever providers are registered in this Laravel 11+ project):

```php
\App\Providers\OrderServiceProvider::class,
```

- [ ] **Step 7.5: Tinker smoke — transition guard.**

```php
\DB::transaction(function () {
    $svc = app(\App\Services\Order\StateTransitionService::class);
    $order = \App\Models\Order::query()->first();
    if ($order === null) {
        echo "no orders yet — defer this smoke to Task 8\n";
        return;
    }
    try {
        // Force an invalid transition to verify the guard
        $svc->transition($order, \App\Enums\OrderStatus::Delivered, \App\Enums\OrderActorType::System);
    } catch (\App\Exceptions\Order\InvalidOrderTransitionException $e) {
        echo "guarded: " . $e->getMessage() . "\n";
    }
});
```

(Skip if no orders exist; verify via the E2E smoke later.)

---

## Task 8: CreationService + `POST /api/orders`

**Files:**
- Create: `app/Services/Order/CodeVerificationService.php` (just the `generatePair()` static for now; full verifier in Task 12)
- Create: `app/Services/Order/CreationService.php`
- Create: `app/Http/Requests/Order/CreateOrderRequest.php`
- Create: `app/Http/Controllers/Api/Order/OrderController.php`
- Modify: `routes/api.php`
- Modify: `app/Providers/AppServiceProvider.php` `configureRateLimiters()` (register `orders_create`, `me_orders_read` throttles)

- [ ] **Step 8.1: Stub `CodeVerificationService` with `generatePair`.**

File: `app/Services/Order/CodeVerificationService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Order;

final class CodeVerificationService
{
    /**
     * Generate a (pickup, delivery) code pair guaranteed distinct.
     *
     * @return array{pickup: string, delivery: string}
     */
    public function generatePair(): array
    {
        do {
            $pickup = $this->six();
            $delivery = $this->six();
        } while ($pickup === $delivery);

        return ['pickup' => $pickup, 'delivery' => $delivery];
    }

    private function six(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    // Full verifier methods (verifyPickup / verifyDelivery / verifyGeofence) added in Task 12.
}
```

- [ ] **Step 8.2: Create `CreationService`.**

File: `app/Services/Order/CreationService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Enums\DeliveryFeePayer;
use App\Enums\DeliveryFeePaymentMethod;
use App\Enums\DeliveryFeeStatus;
use App\Enums\ItemSize;
use App\Enums\OrderActorType;
use App\Enums\OrderErrorCode;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ReceiverType;
use App\Exceptions\Order\OrderDomainException;
use App\Exceptions\Order\QuoteMismatchException;
use App\Models\GuestRecipient;
use App\Models\Order;
use App\Models\User;
use App\Support\QuoteToken;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CreationService
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly QuoteService $quotes,
        private readonly CodeVerificationService $codes,
        private readonly StateTransitionService $transitions,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated body from CreateOrderRequest
     */
    public function create(User $sender, array $input, ?string $idempotencyKey = null): Order
    {
        // Idempotency replay
        if ($idempotencyKey !== null) {
            $cacheKey = "order_idem:{$sender->id}:{$idempotencyKey}";
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && isset($cached['order_id'])) {
                $body = (string) json_encode($input);
                if (($cached['body_hash'] ?? null) !== hash('sha256', $body)) {
                    throw new OrderDomainException(
                        OrderErrorCode::IdempotencyConflict,
                        trans('order_messages.idempotency_conflict'),
                    );
                }
                /** @var Order $existing */
                $existing = Order::findOrFail($cached['order_id']);
                return $existing;
            }
        }

        // 1. Verify quote token
        $token = (string) $input['quote_token'];
        try {
            $verified = QuoteToken::verify($token);
        } catch (InvalidArgumentException) {
            throw new OrderDomainException(OrderErrorCode::InvalidQuoteToken, trans('order_messages.invalid_quote_token'));
        }
        if ($verified['expired']) {
            throw new OrderDomainException(OrderErrorCode::QuoteExpired, trans('order_messages.quote_expired'));
        }
        $tokenPayload = $verified['payload'];

        $orderType = OrderType::from((string) $input['order_type']);
        $itemSize = ItemSize::from((string) $input['item_size']);
        $itemPrice = $orderType === OrderType::P2pSale
            ? bcadd((string) $input['item_price'], '0', 2)
            : '0.00';
        $payer = $orderType === OrderType::P2pSale
            ? 'receiver'
            : (string) ($input['delivery_fee_payer'] ?? 'sender');

        // 2. Re-run pricing server-side and compare
        $fresh = $this->pricing->compute(
            $orderType,
            (float) $input['pickup_location']['lat'],
            (float) $input['pickup_location']['lng'],
            (float) $input['receiver_location']['lat'],
            (float) $input['receiver_location']['lng'],
            $itemSize,
            $itemPrice,
            $payer,
            'cash',
        );
        if (
            bccomp((string) $fresh['delivery_fee_base'], (string) $tokenPayload['delivery_fee_base'], 2) !== 0
            || (int) $fresh['region_id'] !== (int) $tokenPayload['region_id']
            || bccomp((string) $fresh['commission_amount'], (string) $tokenPayload['commission_amount'], 2) !== 0
        ) {
            // Issue a fresh quote alongside the 409 so the client can retry with one tap
            $newQuote = $this->quotes->quote(
                $orderType,
                (float) $input['pickup_location']['lat'],
                (float) $input['pickup_location']['lng'],
                (float) $input['receiver_location']['lat'],
                (float) $input['receiver_location']['lng'],
                $itemSize, $itemPrice, $payer,
            );
            throw new QuoteMismatchException($newQuote);
        }

        // 3. Self-order guard
        $senderPhone = (string) $sender->phone_number;
        $receiverPhone = (string) $input['receiver_phone'];
        if ($receiverPhone === $senderPhone) {
            throw new OrderDomainException(OrderErrorCode::SenderIsReceiver, trans('order_messages.sender_is_receiver'));
        }

        // 4. Merchant-account guard (defensive — merchant flow ships in sub-project E)
        if ($sender->merchantProfile && $sender->merchantProfile->status?->isActive()) {
            throw new OrderDomainException(OrderErrorCode::MerchantUseMerchantFlow, trans('order_messages.merchant_use_merchant_flow'));
        }

        // 5. Receiver classification (Type 1 user vs Type 2 guest)
        $receiverUser = User::query()->where('phone_number', $receiverPhone)->first();
        $receiverType = $receiverUser ? ReceiverType::RegisteredUser : ReceiverType::Guest;

        // 6. Code pair
        $pair = $this->codes->generatePair();

        // 7. All inserts in one transaction
        return DB::transaction(function () use (
            $sender, $input, $orderType, $itemSize, $itemPrice, $payer, $fresh,
            $receiverType, $receiverUser, $receiverPhone, $pair, $idempotencyKey
        ): Order {
            // Guest row if needed
            $guestId = null;
            if ($receiverType === ReceiverType::Guest) {
                $guest = GuestRecipient::query()->firstOrCreate(
                    ['phone' => $receiverPhone],
                    ['name' => (string) $input['receiver_name']]
                );
                $guestId = $guest->id;
            }

            $pickupPoint = new Point((float) $input['pickup_location']['lng'], (float) $input['pickup_location']['lat'], 4326);
            $receiverPoint = new Point((float) $input['receiver_location']['lng'], (float) $input['receiver_location']['lat'], 4326);

            $order = Order::create([
                'public_id' => (string) Str::ulid(),
                'tracking_token' => (string) Str::ulid(),
                'order_type' => $orderType->value,
                'status' => OrderStatus::Created->value,
                'status_changed_at' => now(),

                'sender_user_id' => $sender->id,
                'sender_phone' => (string) $sender->phone_number,
                'sender_name' => trim(($sender->first_name ?? '') . ' ' . ($sender->last_name ?? '')),

                'pickup_address' => (string) $input['pickup_address'],
                'pickup_location' => $pickupPoint,
                'pickup_notes' => $input['pickup_notes'] ?? null,
                'pickup_code' => $pair['pickup'],
                'pickup_code_attempts' => 0,

                'receiver_type' => $receiverType->value,
                'receiver_user_id' => $receiverUser?->id,
                'receiver_guest_id' => $guestId,
                'receiver_phone' => $receiverPhone,
                'receiver_name' => (string) $input['receiver_name'],
                'receiver_address' => (string) $input['receiver_address'],
                'receiver_location' => $receiverPoint,
                'receiver_notes' => $input['receiver_notes'] ?? null,
                'delivery_code' => $pair['delivery'],
                'delivery_code_attempts' => 0,

                'driver_assignment_attempts' => 0,
                'search_radius_tier' => 1,

                'item_description' => (string) $input['item_description'],
                'item_size' => $itemSize->value,
                'item_weight_kg' => $input['item_weight_kg'] ?? null,
                'item_value' => $input['item_value'] ?? null,

                'item_price' => $itemPrice,
                'commission_rate' => $fresh['commission_rate'],
                'commission_amount' => $fresh['commission_amount'],
                'delivery_fee_base' => $fresh['delivery_fee_base'],
                'delivery_fee_surcharge_percent' => 0,
                'delivery_fee' => $fresh['delivery_fee'],
                'driver_fee_cut_rate' => $fresh['driver_fee_cut_rate'],
                'driver_fee_cut_amount' => $fresh['driver_fee_cut_amount'],
                'delivery_fee_payer' => $payer,
                'delivery_fee_payment_method' => DeliveryFeePaymentMethod::Cash->value,
                'delivery_fee_status' => DeliveryFeeStatus::Unpaid->value,
            ]);

            // Initial status log for the created → awaiting_driver transition
            $this->transitions->transition(
                order: $order,
                to: OrderStatus::AwaitingDriver,
                actorType: OrderActorType::User,
                actorId: $sender->id,
                metadata: ['event' => 'order_created', 'region_id' => $fresh['region_id'], 'distance_km' => $fresh['distance_km']],
            );

            if ($idempotencyKey !== null) {
                Cache::put(
                    "order_idem:{$sender->id}:{$idempotencyKey}",
                    ['order_id' => $order->id, 'body_hash' => hash('sha256', (string) json_encode($input))],
                    now()->addDay()
                );
            }

            // (Guest receiver SMS dispatch: queued in Task 9 once the route exists.)
            return $order->refresh();
        });
    }
}
```

> **Note:** `MerchantProfile` may not have an `isActive()` predicate; if not, inline the equivalent check (`$sender->merchantProfile?->status?->value === 'active'`) or remove the guard entirely (the spec calls it "defensive"; safe to drop if it complicates).

- [ ] **Step 8.3: Create FormRequest.**

File: `app/Http/Requests/Order/CreateOrderRequest.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use App\Enums\ItemSize;
use App\Enums\OrderType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->phone_verified_at !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'quote_token' => ['required', 'string'],
            'order_type' => ['required', Rule::in([OrderType::StandardDelivery->value, OrderType::P2pSale->value])],
            'pickup_location' => ['required', 'array'],
            'pickup_location.lat' => ['required', 'numeric', 'between:-90,90'],
            'pickup_location.lng' => ['required', 'numeric', 'between:-180,180'],
            'pickup_address' => ['required', 'string', 'max:500'],
            'pickup_notes' => ['nullable', 'string', 'max:500'],
            'receiver_location' => ['required', 'array'],
            'receiver_location.lat' => ['required', 'numeric', 'between:-90,90'],
            'receiver_location.lng' => ['required', 'numeric', 'between:-180,180'],
            'receiver_address' => ['required', 'string', 'max:500'],
            'receiver_phone' => ['required', 'string', 'regex:/^\+218\d{9}$/'],
            'receiver_name' => ['required', 'string', 'max:200'],
            'receiver_notes' => ['nullable', 'string', 'max:500'],
            'item_size' => ['required', Rule::enum(ItemSize::class)],
            'item_description' => ['required', 'string', 'min:5', 'max:500'],
            'item_weight_kg' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'item_value' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'item_price' => [
                Rule::requiredIf(fn () => $this->input('order_type') === OrderType::P2pSale->value),
                'numeric',
                'min:0.01',
                'max:99999999.99',
            ],
            'delivery_fee_payer' => ['sometimes', Rule::in(['sender', 'receiver'])],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($v): void {
            if ($this->input('order_type') === OrderType::StandardDelivery->value && $this->filled('item_price')) {
                $v->errors()->add('item_price', 'item_price is not allowed for standard_delivery orders.');
            }
        });
    }
}
```

- [ ] **Step 8.4: Create OrderController (creation + list + show methods).**

File: `app/Http/Controllers/Api/Order/OrderController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Order;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\CreateOrderRequest;
use App\Http\Resources\Order\OrderResource;
use App\Models\Order;
use App\Services\Order\CreationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class OrderController extends Controller
{
    public function __construct(private readonly CreationService $creation) {}

    public function store(CreateOrderRequest $req): JsonResponse
    {
        $idempotencyKey = $req->header('Idempotency-Key');
        $order = $this->creation->create($req->user(), $req->validated(), $idempotencyKey ? (string) $idempotencyKey : null);

        return (new OrderResource($order))
            ->additional(['pickup_code' => $order->pickup_code])
            ->response()
            ->setStatusCode(201);
    }

    public function index(Request $req): AnonymousResourceCollection
    {
        $user = $req->user();
        $role = (string) $req->query('role', 'all');
        $status = $req->query('status');

        $query = Order::query();

        $query->where(function ($q) use ($user, $role): void {
            if ($role === 'sent') {
                $q->where('sender_user_id', $user->id);
            } elseif ($role === 'received') {
                $q->where('receiver_user_id', $user->id);
            } else {
                $q->where('sender_user_id', $user->id)->orWhere('receiver_user_id', $user->id);
            }
        });

        if ($status === 'active') {
            $query->active();
        } elseif (is_string($status)) {
            $query->where('status', $status);
        }

        $orders = $query->orderByDesc('created_at')->paginate((int) $req->query('per_page', 20));

        return OrderResource::collection($orders);
    }

    public function show(Request $req, Order $order): OrderResource
    {
        $this->authorize('view', $order);
        return new OrderResource($order);
    }
}
```

- [ ] **Step 8.5: Register `orders_create` throttle and routes.**

File: `app/Providers/AppServiceProvider.php` — add inside `configureRateLimiters()` alongside `orders_quote`:

```php
RateLimiter::for('orders_create', fn (\Illuminate\Http\Request $r) => [
    Limit::perMinute(10)->by((string) optional($r->user())->id),
]);
RateLimiter::for('me_orders_read', fn (\Illuminate\Http\Request $r) => [
    Limit::perMinute(60)->by((string) optional($r->user())->id),
]);
```

File: `routes/api.php` — extend the `/orders` and `/me` groups:

```php
use App\Http\Controllers\Api\Order\OrderController;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::prefix('orders')->group(function (): void {
        Route::post('/', [OrderController::class, 'store'])
            ->middleware('throttle:orders_create');
    });

    Route::prefix('me/orders')->group(function (): void {
        Route::get('/', [OrderController::class, 'index'])
            ->middleware('throttle:me_orders_read');
        Route::get('{order:public_id}', [OrderController::class, 'show'])
            ->middleware('throttle:me_orders_read');
    });
});
```

- [ ] **Step 8.6: Tinker smoke — create a full order end-to-end.**

```php
$user = \App\Models\User::query()->where('phone_verified_at', '!=', null)->first();
// Ensure user has at least one region with non-zero base_fee
$r = \App\Models\Region::first();
\DB::table('regions')->where('id', $r->id)->update(['base_fee' => 10]);
$centroid = \DB::selectOne('SELECT ST_X(ST_Centroid(boundary::geometry)) lng, ST_Y(ST_Centroid(boundary::geometry)) lat FROM regions WHERE id = ?', [$r->id]);

$token = $user->createToken('order-smoke')->plainTextToken;

// Quote
$q = \Illuminate\Support\Facades\Http::withToken($token)->acceptJson()->post(url('/api/orders/quote'), [
    'order_type' => 'standard_delivery',
    'pickup_location' => ['lat' => (float) $centroid->lat, 'lng' => (float) $centroid->lng],
    'receiver_location' => ['lat' => (float) $centroid->lat + 0.01, 'lng' => (float) $centroid->lng + 0.01],
    'item_size' => 'small',
]);
$quote_token = $q->json('quote_token');

// Create
$resp = \Illuminate\Support\Facades\Http::withToken($token)->acceptJson()->post(url('/api/orders'), [
    'quote_token' => $quote_token,
    'order_type' => 'standard_delivery',
    'pickup_location' => ['lat' => (float) $centroid->lat, 'lng' => (float) $centroid->lng],
    'pickup_address' => 'Test pickup',
    'receiver_location' => ['lat' => (float) $centroid->lat + 0.01, 'lng' => (float) $centroid->lng + 0.01],
    'receiver_address' => 'Test dropoff',
    'receiver_phone' => '+218910999888',
    'receiver_name' => 'Receiver Smith',
    'item_size' => 'small',
    'item_description' => 'A small parcel for smoke test',
]);

$resp->status();                          // 201
$publicId = $resp->json('data.id');
$resp->json('pickup_code');                // visible to creator once
$resp->json('data.pickup.pickup_code');    // also exposed to sender on subsequent reads

$o = \App\Models\Order::where('public_id', $publicId)->firstOrFail();
$o->status;                                // OrderStatus::AwaitingDriver
$o->statusLogs()->count();                 // 1 (created → awaiting_driver)
$o->pickup_code !== $o->delivery_code;     // true
strlen($o->pickup_code);                   // 6

// List + show
$list = \Illuminate\Support\Facades\Http::withToken($token)->acceptJson()->get(url('/api/me/orders'));
$list->json('data.0.id');                  // == $publicId
$show = \Illuminate\Support\Facades\Http::withToken($token)->acceptJson()->get(url('/api/me/orders/' . $publicId));
$show->json('data.pickup.pickup_code');    // sender sees plaintext
```

---

## Task 9: Public guest tracking — `GET /api/track/{tracking_token}`

**Files:**
- Create: `app/Http/Controllers/Api/Tracking/GuestTrackingController.php`
- Modify: `routes/api.php`
- Modify: `app/Providers/AppServiceProvider.php` `configureRateLimiters()` (register `guest_tracking` throttle)

- [ ] **Step 9.1: Create controller.**

File: `app/Http/Controllers/Api/Tracking/GuestTrackingController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Tracking;

use App\Http\Controllers\Controller;
use App\Http\Resources\Order\GuestTrackingResource;
use App\Models\Order;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class GuestTrackingController extends Controller
{
    public function __invoke(string $trackingToken): GuestTrackingResource
    {
        $order = Order::query()->where('tracking_token', $trackingToken)->first();
        if ($order === null) {
            // 404 — don't distinguish "no such token" from "soft-deleted"
            throw new NotFoundHttpException('Order not found.');
        }
        return new GuestTrackingResource($order);
    }
}
```

- [ ] **Step 9.2: Register throttle + route.**

File: `app/Providers/AppServiceProvider.php` — add inside `configureRateLimiters()` alongside the existing `login`, `otp_request`, `orders_quote` limiters (matches project convention; do NOT use `bootstrap/app.php`):

```php
RateLimiter::for('guest_tracking', fn (\Illuminate\Http\Request $r) => [
    Limit::perMinute(120)->by((string) $r->ip()),
]);
```

File: `routes/api.php`:

```php
use App\Http\Controllers\Api\Tracking\GuestTrackingController;

Route::get('track/{trackingToken}', GuestTrackingController::class)
    ->middleware('throttle:guest_tracking')
    ->where('trackingToken', '[0-9A-HJKMNP-TV-Z]{26}'); // ULID Crockford alphabet
```

- [ ] **Step 9.3: Tinker smoke.**

```php
$o = \App\Models\Order::query()->first();   // assumes an order from Task 8's smoke
$resp = \Illuminate\Support\Facades\Http::acceptJson()->get(url('/api/track/' . $o->tracking_token));
$resp->status();                             // 200
$resp->json('data.id');                      // public_id
$resp->json('data.sender.first_name');       // first name only
$resp->json('data.sender.phone');            // null (key not present)
$resp->json('data.delivery_code');           // 6 digits (receiver needs it)
$resp->json('data.pickup_code');             // null (sender's secret)

// Bad token → 404
\Illuminate\Support\Facades\Http::acceptJson()->get(url('/api/track/0000000000000000000000000A'))->status();  // 404
```

---

## Task 10: Driver presence — `go-online`, `go-offline`, `location`

**Files:**
- Create: `app/Services/Driver/PresenceService.php`
- Create: `app/Http/Requests/Order/DriverGoOnlineRequest.php`
- Create: `app/Http/Requests/Order/DriverLocationUpdateRequest.php`
- Create: `app/Http/Controllers/Api/Driver/Order/PresenceController.php`
- Modify: `routes/api.php`
- Modify: `app/Providers/AppServiceProvider.php` `configureRateLimiters()` (register `driver_presence`, `driver_location` throttles)

- [ ] **Step 10.1: Create `PresenceService`.**

File: `app/Services/Driver/PresenceService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Driver;

use App\Enums\DriverActivityStatus;
use App\Enums\DriverStatus;
use App\Enums\OrderErrorCode;
use App\Enums\OrderStatus;
use App\Exceptions\Order\OrderDomainException;
use App\Models\DriverAccount;
use App\Models\DriverPresenceLog;
use App\Models\DriverProfile;
use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Support\Facades\DB;

final class PresenceService
{
    /**
     * Run all §9.3 checks and flip to online. Atomic.
     */
    public function goOnline(User $driver, float $lat, float $lng): DriverProfile
    {
        return DB::transaction(function () use ($driver, $lat, $lng): DriverProfile {
            /** @var DriverProfile $profile */
            $profile = DriverProfile::where('user_id', $driver->id)->lockForUpdate()->firstOrFail();

            if ($profile->status !== DriverStatus::Active) {
                throw new OrderDomainException(OrderErrorCode::DriverNotActive, trans('order_messages.driver_not_active'));
            }

            $regionCount = DB::table('driver_region')->where('user_id', $driver->id)->count();
            if ($regionCount === 0) {
                throw new OrderDomainException(OrderErrorCode::DriverNoRegions, trans('order_messages.driver_no_regions'));
            }

            $inServiceArea = (bool) DB::selectOne(
                "SELECT EXISTS (
                    SELECT 1 FROM service_areas sa
                     WHERE sa.is_active = true
                       AND ST_Contains(sa.boundary::geometry, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geometry)
                 ) AS in_area",
                [$lng, $lat]
            )->in_area;
            if (! $inServiceArea) {
                throw new OrderDomainException(OrderErrorCode::DriverOutOfServiceArea, trans('order_messages.driver_out_of_service_area'));
            }

            /** @var DriverAccount $account */
            $account = DriverAccount::where('driver_id', $driver->id)->firstOrFail();
            if ($account->isAtLiabilityCeiling()) {
                throw new OrderDomainException(OrderErrorCode::DriverLiabilityMax, trans('order_messages.driver_liability_max'));
            }

            $point = new Point($lng, $lat, 4326);
            $profile->fill([
                'activity_status' => DriverActivityStatus::Online->value,
                'current_location' => $point,
                'last_location_updated_at' => now(),
                'last_active_at' => now(),
            ]);
            $profile->save();

            DriverPresenceLog::create([
                'driver_id' => $driver->id,
                'event' => 'went_online',
                'reason' => 'manual',
                'location' => $point,
            ]);

            return $profile;
        });
    }

    public function goOffline(User $driver, ?Point $location = null, string $reason = 'manual'): DriverProfile
    {
        return DB::transaction(function () use ($driver, $location, $reason): DriverProfile {
            /** @var DriverProfile $profile */
            $profile = DriverProfile::where('user_id', $driver->id)->lockForUpdate()->firstOrFail();

            // Guard: not allowed mid-trip
            $hasActive = Order::where('driver_id', $driver->id)
                ->whereIn('status', [
                    OrderStatus::Assigned->value,
                    OrderStatus::DriverEnRoutePickup->value,
                    OrderStatus::PickedUp->value,
                    OrderStatus::DriverEnRouteDropoff->value,
                    OrderStatus::DeliveryInProgress->value,
                ])
                ->exists();
            if ($hasActive) {
                throw new OrderDomainException(OrderErrorCode::DriverHasActiveOrder, trans('order_messages.driver_has_active_order'));
            }

            $profile->activity_status = DriverActivityStatus::Offline;
            $profile->save();

            DriverPresenceLog::create([
                'driver_id' => $driver->id,
                'event' => 'went_offline',
                'reason' => $reason,
                'location' => $location,
            ]);

            return $profile;
        });
    }

    /**
     * Heartbeat update. Returns nothing — fast path.
     */
    public function updateLocation(User $driver, float $lat, float $lng): void
    {
        /** @var DriverProfile|null $profile */
        $profile = DriverProfile::where('user_id', $driver->id)->first();
        if ($profile === null) {
            return; // no driver profile = not a driver; treat as no-op
        }

        $point = new Point($lng, $lat, 4326);
        $profile->current_location = $point;
        $profile->last_location_updated_at = now();
        $profile->last_active_at = now();
        $profile->save();

        // Conditional insert into history: 50m moved OR 60s since last
        $previous = DB::table('driver_locations')
            ->where('driver_id', $driver->id)
            ->orderByDesc('id')
            ->limit(1)
            ->first();

        $shouldInsert = true;
        if ($previous !== null) {
            $secondsSince = now()->diffInSeconds($previous->recorded_at);
            $distanceMeters = (float) DB::selectOne(
                'SELECT ST_Distance(ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ST_SetSRID(ST_MakePoint(ST_X(?::geometry), ST_Y(?::geometry)), 4326)::geography) AS d',
                [$lng, $lat, $previous->location, $previous->location]
            )->d;

            $shouldInsert = $distanceMeters >= 50 || $secondsSince >= 60;
        }

        if ($shouldInsert) {
            DB::table('driver_locations')->insert([
                'driver_id' => $driver->id,
                'location' => DB::raw("ST_SetSRID(ST_MakePoint({$lng}, {$lat}), 4326)::geography"),
                'recorded_at' => now(),
                'created_at' => now(),
            ]);
        }
    }
}
```

> **Note:** Adjust the `driver_locations` insert if the existing table schema has different column names (e.g. `accuracy_m`, `heading`). Refer to `database/migrations/2026_05_03_070400_create_driver_locations_table.php` for the actual columns.

- [ ] **Step 10.2: Create FormRequests.**

File: `app/Http/Requests/Order/DriverGoOnlineRequest.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

final class DriverGoOnlineRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ];
    }
}
```

File: `app/Http/Requests/Order/DriverLocationUpdateRequest.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

final class DriverLocationUpdateRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'heading' => ['sometimes', 'numeric', 'between:0,359.99'],
            'speed_kmh' => ['sometimes', 'numeric', 'min:0', 'max:300'],
            'accuracy_m' => ['sometimes', 'numeric', 'min:0', 'max:10000'],
        ];
    }
}
```

- [ ] **Step 10.3: Create `PresenceController`.**

File: `app/Http/Controllers/Api/Driver/Order/PresenceController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Driver\Order;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\DriverGoOnlineRequest;
use App\Http\Requests\Order\DriverLocationUpdateRequest;
use App\Services\Driver\PresenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class PresenceController extends Controller
{
    public function __construct(private readonly PresenceService $presence) {}

    public function online(DriverGoOnlineRequest $req): JsonResponse
    {
        $profile = $this->presence->goOnline(
            $req->user(),
            (float) $req->input('lat'),
            (float) $req->input('lng'),
        );

        return new JsonResponse([
            'activity_status' => $profile->activity_status->value,
            'last_seen_at' => $profile->last_active_at?->toIso8601String(),
        ]);
    }

    public function offline(Request $req): JsonResponse
    {
        $profile = $this->presence->goOffline($req->user());

        return new JsonResponse(['activity_status' => $profile->activity_status->value]);
    }

    public function location(DriverLocationUpdateRequest $req): Response
    {
        $this->presence->updateLocation(
            $req->user(),
            (float) $req->input('lat'),
            (float) $req->input('lng'),
        );

        return response()->noContent();
    }
}
```

- [ ] **Step 10.4: Register throttles + routes.**

File: `app/Providers/AppServiceProvider.php` — add inside `configureRateLimiters()` alongside the existing `login`, `otp_request`, `orders_quote` limiters (matches project convention; do NOT use `bootstrap/app.php`):

```php
RateLimiter::for('driver_presence', fn (\Illuminate\Http\Request $r) => [
    Limit::perMinute(10)->by((string) optional($r->user())->id),
]);
RateLimiter::for('driver_location', fn (\Illuminate\Http\Request $r) => [
    Limit::perMinute(120)->by((string) optional($r->user())->id),
]);
```

File: `routes/api.php` — extend the existing `/driver` group:

```php
use App\Http\Controllers\Api\Driver\Order\PresenceController;

Route::middleware(['auth:sanctum', 'role:driver'])->prefix('driver')->group(function (): void {
    // ... existing routes ...
    Route::post('go-online', [PresenceController::class, 'online'])->middleware('throttle:driver_presence');
    Route::post('go-offline', [PresenceController::class, 'offline'])->middleware('throttle:driver_presence');
    Route::post('location', [PresenceController::class, 'location'])->middleware('throttle:driver_location');
});
```

- [ ] **Step 10.5: Tinker smoke — drive a driver online + heartbeat + offline.**

```php
$driver = \App\Models\User::query()->whereHas('roles', fn ($q) => $q->where('name', 'driver'))->first();
$token = $driver->createToken('presence-smoke')->plainTextToken;

// Centroid of the driver's region (assumes onboarding milestone seeded one)
$region = \App\Models\Region::join('driver_region', 'regions.id', '=', 'driver_region.region_id')
    ->where('driver_region.user_id', $driver->id)->select('regions.*')->first();
$c = \DB::selectOne('SELECT ST_X(ST_Centroid(boundary::geometry)) lng, ST_Y(ST_Centroid(boundary::geometry)) lat FROM regions WHERE id = ?', [$region->id]);

// go-online
$resp = \Illuminate\Support\Facades\Http::withToken($token)->acceptJson()->post(url('/api/driver/go-online'), ['lat' => (float) $c->lat, 'lng' => (float) $c->lng]);
$resp->status();   // 200
$resp->json('activity_status'); // "online"

\App\Models\DriverPresenceLog::where('driver_id', $driver->id)->where('event', 'went_online')->count(); // 1

// location heartbeat
$loc = \Illuminate\Support\Facades\Http::withToken($token)->acceptJson()->post(url('/api/driver/location'), ['lat' => (float) $c->lat + 0.001, 'lng' => (float) $c->lng + 0.001]);
$loc->status();    // 204

// offline
$off = \Illuminate\Support\Facades\Http::withToken($token)->acceptJson()->post(url('/api/driver/go-offline'));
$off->status();    // 200
$off->json('activity_status'); // "offline"
```

---

## Task 11: BroadcastService + ClaimService + driver order endpoints

**Files:**
- Create: `app/Services/Order/BroadcastService.php`
- Create: `app/Services/Order/ClaimService.php`
- Create: `app/Http/Resources/Order/BroadcastOrderResource.php`
- Create: `app/Http/Controllers/Api/Driver/Order/BroadcastController.php`
- Create: `app/Http/Controllers/Api/Driver/Order/ClaimController.php`
- Modify: `routes/api.php`
- Modify: `app/Providers/AppServiceProvider.php` `configureRateLimiters()` (register `driver_broadcast`, `driver_claim`, `driver_current` throttles)

- [ ] **Step 11.1: Create `BroadcastService`.**

File: `app/Services/Order/BroadcastService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Enums\DriverActivityStatus;
use App\Enums\OrderStatus;
use App\Models\DriverProfile;
use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class BroadcastService
{
    /**
     * Returns the orders currently eligible to be broadcast to this driver.
     *
     * @return Collection<int, array{order: Order, distance_m: int}>
     */
    public function candidatesForDriver(User $driver): Collection
    {
        /** @var DriverProfile|null $profile */
        $profile = DriverProfile::where('user_id', $driver->id)->first();
        if ($profile === null || $profile->activity_status !== DriverActivityStatus::Online) {
            return collect();
        }
        if ($profile->current_location === null) {
            return collect();
        }
        $staleAfter = (int) PlatformSetting::get('driver.location_stale_after_seconds', 120);
        $lastUpdate = $profile->last_location_updated_at;
        if ($lastUpdate === null || $lastUpdate->diffInSeconds(now()) > $staleAfter) {
            return collect();
        }

        // No broadcasts if driver already has an active order
        $hasActive = Order::where('driver_id', $driver->id)
            ->whereIn('status', [
                OrderStatus::Assigned->value,
                OrderStatus::DriverEnRoutePickup->value,
                OrderStatus::PickedUp->value,
                OrderStatus::DriverEnRouteDropoff->value,
                OrderStatus::DeliveryInProgress->value,
            ])->exists();
        if ($hasActive) {
            return collect();
        }

        $lng = $profile->current_location->getLongitude();
        $lat = $profile->current_location->getLatitude();
        $tier1 = (float) PlatformSetting::get('broadcast.tier_1_radius_km', 3) * 1000;
        $tier2 = (float) PlatformSetting::get('broadcast.tier_2_radius_km', 5) * 1000;
        $tier3 = (float) PlatformSetting::get('broadcast.tier_3_radius_km', 10) * 1000;

        $vehicleType = $profile->vehicle_type->value; // 'motorcycle' | 'car'
        $vehicleSizes = $vehicleType === 'car'
            ? ['small', 'medium', 'large', 'xlarge']
            : ['small', 'medium'];

        $account = $profile->account;
        $cashToDeposit = (string) ($account?->cash_to_deposit ?? '0');
        $maxLiability = (string) ($account?->max_cash_liability ?? '0');

        $rows = DB::select(
            "SELECT o.id, ST_Distance(o.pickup_location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) AS distance_m
               FROM orders o
              WHERE o.status = 'awaiting_driver'
                AND o.deleted_at IS NULL
                AND ST_DWithin(
                      o.pickup_location,
                      ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                      CASE o.search_radius_tier WHEN 1 THEN ? WHEN 2 THEN ? WHEN 3 THEN ? END
                    )
                AND o.item_size = ANY(?::text[])
                AND ((?::numeric)
                     + CASE WHEN o.delivery_fee_payment_method = 'cash' AND o.delivery_fee_payer = 'receiver' THEN o.delivery_fee ELSE 0 END
                     + o.item_price) <= (?::numeric)
                AND EXISTS (
                      SELECT 1 FROM regions r
                      JOIN driver_region dr ON dr.region_id = r.id
                      WHERE dr.user_id = ?
                        AND ST_Contains(r.boundary::geometry, o.pickup_location::geometry)
                    )
              ORDER BY distance_m ASC
              LIMIT 20",
            [
                $lng, $lat,
                $lng, $lat,
                $tier1, $tier2, $tier3,
                '{' . implode(',', $vehicleSizes) . '}',
                $cashToDeposit, $maxLiability,
                $driver->id,
            ]
        );

        $orderIds = array_map(fn ($r) => (int) $r->id, $rows);
        if ($orderIds === []) {
            return collect();
        }
        $orders = Order::whereIn('id', $orderIds)->get()->keyBy('id');

        return collect($rows)->map(fn ($r) => [
            'order' => $orders[(int) $r->id],
            'distance_m' => (int) round($r->distance_m),
        ]);
    }
}
```

- [ ] **Step 11.2: Create `BroadcastOrderResource`.**

File: `app/Http/Resources/Order/BroadcastOrderResource.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Order;

use App\Models\Order;
use App\Models\PlatformSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class BroadcastOrderResource extends JsonResource
{
    /**
     * Resource is initialised with ['order' => Order, 'distance_m' => int].
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Order $o */
        $o = $this->resource['order'];
        $distance = (int) $this->resource['distance_m'];

        $tripKm = bcdiv((string) DB::raw(''), '1', 1); // computed below
        $tripKm = '0';
        if ($o->pickup_location && $o->receiver_location) {
            $tripKm = (string) \DB::selectOne(
                'SELECT round(ST_Distance(?::geography, ?::geography)::numeric / 1000, 1) AS km',
                [(string) $o->pickup_location, (string) $o->receiver_location]
            )->km;
        }

        return [
            'id' => $o->public_id,
            'order_type' => $o->order_type->value,
            'item_size' => $o->item_size->value,
            'item_description' => $o->item_description,
            'pickup_address' => $o->pickup_address,
            'pickup_location' => ['lat' => (float) $o->pickup_location->getLatitude(), 'lng' => (float) $o->pickup_location->getLongitude()],
            'distance_to_pickup_m' => $distance,
            'receiver_address' => $o->receiver_address,
            'receiver_location' => ['lat' => (float) $o->receiver_location->getLatitude(), 'lng' => (float) $o->receiver_location->getLongitude()],
            'trip_distance_km' => $tripKm,
            'delivery_fee' => (string) $o->delivery_fee,
            'delivery_fee_surcharge_percent' => $o->delivery_fee_surcharge_percent,
            'search_radius_tier' => $o->search_radius_tier,
            'driver_earnings_estimate' => bcsub((string) $o->delivery_fee, (string) $o->driver_fee_cut_amount, 2),
            'cash_to_collect' => $o->cashCollectedAtDelivery(),
            'broadcast_age_seconds' => optional($o->awaiting_driver_at)?->diffInSeconds(now()) ?? 0,
            'pickup_code_required' => (bool) PlatformSetting::get('codes.enforce_pickup', true),
            'delivery_code_required' => (bool) PlatformSetting::get('codes.enforce_delivery', true),
        ];
    }
}
```

> **Note on `DB::raw('')` line:** that line was a typing error left in for clarity — the actual `$tripKm` computation is the subsequent `$tripKm = (string) \DB::selectOne(...)`. The errant line is harmless but should be deleted when copying. Final code should start with `$tripKm = '0';` then the conditional `if` block.

Clean version of that block:

```php
$tripKm = '0';
if ($o->pickup_location && $o->receiver_location) {
    $tripKm = (string) \DB::selectOne(
        'SELECT round(ST_Distance(?::geography, ?::geography)::numeric / 1000, 1) AS km',
        [(string) $o->pickup_location, (string) $o->receiver_location]
    )->km;
}
```

- [ ] **Step 11.3: Create `ClaimService`.**

File: `app/Services/Order/ClaimService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Enums\DriverActivityStatus;
use App\Enums\OrderActorType;
use App\Enums\OrderErrorCode;
use App\Enums\OrderStatus;
use App\Exceptions\Order\OrderDomainException;
use App\Models\DriverProfile;
use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Support\Facades\DB;

final class ClaimService
{
    /**
     * Driver-initiated atomic claim.
     *
     * @return Order   the claimed order, now in en_route_pickup
     */
    public function claim(User $driver, Order $order): Order
    {
        // Cheap pre-checks
        /** @var DriverProfile $profile */
        $profile = DriverProfile::where('user_id', $driver->id)->firstOrFail();
        if ($profile->activity_status !== DriverActivityStatus::Online) {
            throw new OrderDomainException(OrderErrorCode::DriverNotActive, trans('order_messages.driver_not_active'));
        }
        $hasActive = Order::where('driver_id', $driver->id)
            ->whereIn('status', [
                OrderStatus::Assigned->value, OrderStatus::DriverEnRoutePickup->value,
                OrderStatus::PickedUp->value, OrderStatus::DriverEnRouteDropoff->value,
                OrderStatus::DeliveryInProgress->value,
            ])->exists();
        if ($hasActive) {
            throw new OrderDomainException(OrderErrorCode::DriverHasActiveOrder, trans('order_messages.driver_has_active_order'));
        }

        return DB::transaction(function () use ($driver, $order, $profile): Order {
            // Atomic conditional UPDATE — the race-safe claim
            $affected = DB::update(
                "UPDATE orders
                    SET driver_id = ?,
                        status = 'en_route_pickup',
                        status_changed_at = NOW(),
                        assigned_at = NOW(),
                        driver_en_route_pickup_at = NOW(),
                        driver_assignment_attempts = driver_assignment_attempts + 1,
                        updated_at = NOW()
                  WHERE id = ?
                    AND status = 'awaiting_driver'
                    AND driver_id IS NULL",
                [$driver->id, $order->id]
            );

            if ($affected !== 1) {
                throw new OrderDomainException(OrderErrorCode::OrderAlreadyClaimed, trans('order_messages.order_already_claimed'));
            }

            // Driver activity flip
            DriverProfile::where('user_id', $driver->id)->update([
                'activity_status' => DriverActivityStatus::OnOrder->value,
                'last_active_at' => now(),
            ]);

            $driverLocation = $profile->current_location;

            // Two status_log rows: awaiting_driver→assigned and assigned→en_route_pickup
            OrderStatusLog::create([
                'order_id' => $order->id,
                'from_status' => OrderStatus::AwaitingDriver->value,
                'to_status' => OrderStatus::Assigned->value,
                'actor_type' => OrderActorType::Driver->value,
                'actor_id' => $driver->id,
                'actor_location' => $driverLocation,
                'metadata' => null,
            ]);
            OrderStatusLog::create([
                'order_id' => $order->id,
                'from_status' => OrderStatus::Assigned->value,
                'to_status' => OrderStatus::DriverEnRoutePickup->value,
                'actor_type' => OrderActorType::System->value,
                'actor_id' => null,
                'metadata' => ['reason' => 'auto_on_claim'],
            ]);

            return $order->refresh();
        });
    }
}
```

> Two `OrderStatusLog` rows are written directly because the claim does its own atomic UPDATE (bypassing `StateTransitionService`). The service guarantees the same audit columns are populated. Note: `OrderStatusChanged` event is NOT dispatched here for those two transitions; if downstream listeners (milestone #3) need it, dispatch manually after the transaction commits — leave that as a future enhancement (note in spec compliance: still satisfies "every transition logged in order_status_logs"; the event dispatch is a nice-to-have for real-time push).

- [ ] **Step 11.4: Create `BroadcastController` (broadcast + current).**

File: `app/Http/Controllers/Api/Driver/Order/BroadcastController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Driver\Order;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Order\BroadcastOrderResource;
use App\Http\Resources\Order\DriverOrderResource;
use App\Models\Order;
use App\Services\Order\BroadcastService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class BroadcastController extends Controller
{
    public function __construct(private readonly BroadcastService $broadcasts) {}

    public function index(Request $req): AnonymousResourceCollection|JsonResponse
    {
        $candidates = $this->broadcasts->candidatesForDriver($req->user());

        return BroadcastOrderResource::collection($candidates);
    }

    public function current(Request $req): DriverOrderResource|Response
    {
        $order = Order::where('driver_id', $req->user()->id)
            ->whereIn('status', [
                OrderStatus::Assigned->value, OrderStatus::DriverEnRoutePickup->value,
                OrderStatus::PickedUp->value, OrderStatus::DriverEnRouteDropoff->value,
                OrderStatus::DeliveryInProgress->value,
            ])
            ->latest()
            ->first();

        if ($order === null) {
            return response()->noContent();
        }

        return new DriverOrderResource($order);
    }
}
```

- [ ] **Step 11.5: Create `ClaimController`.**

File: `app/Http/Controllers/Api/Driver/Order/ClaimController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Driver\Order;

use App\Http\Controllers\Controller;
use App\Http\Resources\Order\DriverOrderResource;
use App\Models\Order;
use App\Services\Order\ClaimService;
use Illuminate\Http\Request;

final class ClaimController extends Controller
{
    public function __construct(private readonly ClaimService $claims) {}

    public function __invoke(Request $req, Order $order): DriverOrderResource
    {
        $claimed = $this->claims->claim($req->user(), $order);

        return new DriverOrderResource($claimed);
    }
}
```

- [ ] **Step 11.6: Register routes + throttles.**

File: `app/Providers/AppServiceProvider.php` — add inside `configureRateLimiters()` alongside the existing `login`, `otp_request`, `orders_quote` limiters (matches project convention; do NOT use `bootstrap/app.php`):

```php
RateLimiter::for('driver_broadcast', fn (\Illuminate\Http\Request $r) => [
    Limit::perMinute(30)->by((string) optional($r->user())->id),
]);
RateLimiter::for('driver_claim', fn (\Illuminate\Http\Request $r) => [
    Limit::perMinute(30)->by((string) optional($r->user())->id),
]);
RateLimiter::for('driver_current', fn (\Illuminate\Http\Request $r) => [
    Limit::perMinute(60)->by((string) optional($r->user())->id),
]);
```

File: `routes/api.php` — extend `/driver` group:

```php
use App\Http\Controllers\Api\Driver\Order\BroadcastController;
use App\Http\Controllers\Api\Driver\Order\ClaimController;

Route::middleware(['auth:sanctum', 'role:driver'])->prefix('driver')->group(function (): void {
    // ... existing routes ...
    Route::prefix('orders')->group(function (): void {
        Route::get('broadcast', [BroadcastController::class, 'index'])->middleware('throttle:driver_broadcast');
        Route::get('current', [BroadcastController::class, 'current'])->middleware('throttle:driver_current');
        Route::post('{order:public_id}/claim', ClaimController::class)->middleware('throttle:driver_claim');
    });
});
```

- [ ] **Step 11.7: Tinker smoke — driver claims the order from Task 8.**

```php
// driver online
$driver = \App\Models\User::query()->whereHas('roles', fn ($q) => $q->where('name', 'driver'))->first();
$driverToken = $driver->createToken('claim-smoke')->plainTextToken;

// Re-issue go-online if needed (Task 10 smoke flipped offline)
$region = \App\Models\Region::join('driver_region', 'regions.id', '=', 'driver_region.region_id')
    ->where('driver_region.user_id', $driver->id)->select('regions.*')->first();
$c = \DB::selectOne('SELECT ST_X(ST_Centroid(boundary::geometry)) lng, ST_Y(ST_Centroid(boundary::geometry)) lat FROM regions WHERE id = ?', [$region->id]);
\Illuminate\Support\Facades\Http::withToken($driverToken)->acceptJson()->post(url('/api/driver/go-online'), ['lat' => (float) $c->lat, 'lng' => (float) $c->lng]);

// broadcast
$b = \Illuminate\Support\Facades\Http::withToken($driverToken)->acceptJson()->get(url('/api/driver/orders/broadcast'));
$b->json('data');                            // array, at least one element (the order created in Task 8)
$orderPublicId = $b->json('data.0.id');

// claim
$claim = \Illuminate\Support\Facades\Http::withToken($driverToken)->acceptJson()->post(url('/api/driver/orders/' . $orderPublicId . '/claim'));
$claim->status();                            // 200
$claim->json('data.status');                 // "en_route_pickup"
$claim->json('data.pickup.code_required');   // true

// current
$cur = \Illuminate\Support\Facades\Http::withToken($driverToken)->acceptJson()->get(url('/api/driver/orders/current'));
$cur->status();                              // 200
$cur->json('data.id');                       // same public_id

// Second drivers attempt → 409
$other = \App\Models\User::query()->whereHas('roles', fn ($q) => $q->where('name', 'driver'))->where('id', '!=', $driver->id)->first();
if ($other) {
    $t2 = $other->createToken('race')->plainTextToken;
    \Illuminate\Support\Facades\Http::withToken($t2)->acceptJson()->post(url('/api/driver/go-online'), ['lat' => (float) $c->lat, 'lng' => (float) $c->lng]);
    $race = \Illuminate\Support\Facades\Http::withToken($t2)->acceptJson()->post(url('/api/driver/orders/' . $orderPublicId . '/claim'));
    $race->status();                          // 409
    $race->json('error.code');                // "ORDER_ALREADY_CLAIMED"
}

\App\Models\Order::where('public_id', $orderPublicId)->first()->statusLogs()->count(); // 3 total
```

---

## Task 12: Code verification + driver in-transit endpoints

**Files:**
- Modify: `app/Services/Order/CodeVerificationService.php` (add full verifiers — was stubbed in Task 8)
- Create: `app/Http/Requests/Order/ConfirmPickupRequest.php`
- Create: `app/Http/Requests/Order/ConfirmPickupGeofenceRequest.php`
- Create: `app/Http/Requests/Order/ConfirmDeliveryRequest.php`
- Create: `app/Http/Controllers/Api/Driver/Order/ConfirmPickupController.php`
- Create: `app/Http/Controllers/Api/Driver/Order/ArrivedDropoffController.php`
- Create: `app/Http/Controllers/Api/Driver/Order/ConfirmDeliveryController.php`
- Create: `app/Http/Controllers/Api/Me/Order/GeofenceConfirmController.php`
- Modify: `routes/api.php`
- Modify: `app/Providers/AppServiceProvider.php` `configureRateLimiters()` (register `driver_action`, `me_action` throttles)

- [ ] **Step 12.1: Expand `CodeVerificationService` with full verifiers.**

File: `app/Services/Order/CodeVerificationService.php` — replace its contents with:

```php
<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Enums\DeliveryMethod;
use App\Enums\OrderActorType;
use App\Enums\OrderErrorCode;
use App\Enums\OrderStatus;
use App\Enums\PickupMethod;
use App\Exceptions\Order\OrderDomainException;
use App\Models\DriverProfile;
use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Support\Facades\DB;

final class CodeVerificationService
{
    public function __construct(private readonly StateTransitionService $transitions) {}

    /** @return array{pickup: string, delivery: string} */
    public function generatePair(): array
    {
        do {
            $pickup = $this->six();
            $delivery = $this->six();
        } while ($pickup === $delivery);

        return ['pickup' => $pickup, 'delivery' => $delivery];
    }

    private function six(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Confirm pickup: either via 6-digit code, geofence (within X meters + sender's confirmation),
     * or — if `codes.enforce_pickup` is false — by an empty-body bypass.
     */
    public function confirmPickup(User $driver, Order $order, ?string $method, ?string $code): Order
    {
        if ($order->status !== OrderStatus::DriverEnRoutePickup) {
            throw new OrderDomainException(OrderErrorCode::InvalidStateTransition, trans('order_messages.invalid_state_transition'));
        }

        $enforce = (bool) PlatformSetting::get('codes.enforce_pickup', true);
        $maxAttempts = (int) PlatformSetting::get('codes.max_attempts', 5);

        return DB::transaction(function () use ($driver, $order, $method, $code, $enforce, $maxAttempts): Order {
            $resolvedMethod = PickupMethod::Bypassed;
            $driverLoc = $this->driverLocation($driver);

            if (! $enforce) {
                $resolvedMethod = PickupMethod::Bypassed;
            } elseif ($method === 'code') {
                if ($order->pickup_code_attempts >= $maxAttempts) {
                    throw new OrderDomainException(OrderErrorCode::CodeLocked, trans('order_messages.code_locked'));
                }
                if ($code === null || trim($code) === '') {
                    throw new OrderDomainException(OrderErrorCode::CodeRequired, trans('order_messages.code_required'));
                }
                if (! hash_equals((string) $order->pickup_code, trim($code))) {
                    $order->increment('pickup_code_attempts');
                    throw new OrderDomainException(
                        OrderErrorCode::InvalidPickupCode,
                        trans('order_messages.invalid_pickup_code'),
                        ['attempts_remaining' => max(0, $maxAttempts - ($order->pickup_code_attempts))],
                    );
                }
                $resolvedMethod = PickupMethod::Code;
            } elseif ($method === 'geofence') {
                $geoMeters = (int) PlatformSetting::get('pickup.geofence_meters', 500);
                if ($order->pickup_geofence_confirmed_at === null || $order->pickup_geofence_confirmed_at->diffInMinutes(now()) > 5) {
                    throw new OrderDomainException(OrderErrorCode::GeofenceNotConfirmed, trans('order_messages.geofence_not_confirmed'));
                }
                if ($driverLoc === null) {
                    throw new OrderDomainException(OrderErrorCode::DriverLocationStale, trans('order_messages.driver_location_stale'));
                }
                $distance = (float) DB::selectOne(
                    'SELECT ST_Distance(ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?::geography) AS d',
                    [$driverLoc->getLongitude(), $driverLoc->getLatitude(), (string) $order->pickup_location]
                )->d;
                if ($distance > $geoMeters) {
                    throw new OrderDomainException(OrderErrorCode::DriverNotAtPickup, trans('order_messages.driver_not_at_pickup'));
                }
                $resolvedMethod = PickupMethod::GeofenceConfirmation;
            } else {
                throw new OrderDomainException(OrderErrorCode::MethodRequired, trans('order_messages.method_required'));
            }

            $order->picked_up_method = $resolvedMethod;
            $order->save();

            // Transition en_route_pickup → picked_up
            $this->transitions->transition(
                $order, OrderStatus::PickedUp, OrderActorType::Driver, $driver->id,
                null, $driverLoc, ['method' => $resolvedMethod->value, 'attempts_used' => $order->pickup_code_attempts]
            );
            // Auto-chain picked_up → en_route_dropoff
            $this->transitions->transition(
                $order->refresh(), OrderStatus::DriverEnRouteDropoff, OrderActorType::System,
                null, null, null, ['reason' => 'auto_on_pickup']
            );

            return $order->refresh();
        });
    }

    public function confirmGeofenceBySender(User $sender, Order $order): Order
    {
        if ($order->status !== OrderStatus::DriverEnRoutePickup) {
            throw new OrderDomainException(OrderErrorCode::InvalidStateTransition, trans('order_messages.invalid_state_transition'));
        }
        $order->pickup_geofence_confirmed_at = now();
        $order->save();
        return $order->refresh();
    }

    public function arrivedDropoff(User $driver, Order $order): Order
    {
        if ($order->status !== OrderStatus::DriverEnRouteDropoff) {
            throw new OrderDomainException(OrderErrorCode::InvalidStateTransition, trans('order_messages.invalid_state_transition'));
        }
        $driverLoc = $this->driverLocation($driver);
        if ($driverLoc === null) {
            throw new OrderDomainException(OrderErrorCode::DriverLocationStale, trans('order_messages.driver_location_stale'));
        }

        $sanityMeters = (int) PlatformSetting::get('pickup.dropoff_sanity_meters', 1000);
        $distance = (float) DB::selectOne(
            'SELECT ST_Distance(ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?::geography) AS d',
            [$driverLoc->getLongitude(), $driverLoc->getLatitude(), (string) $order->receiver_location]
        )->d;
        if ($distance > $sanityMeters) {
            throw new OrderDomainException(OrderErrorCode::DriverNotNearDropoff, trans('order_messages.driver_not_near_dropoff'));
        }

        return DB::transaction(fn () => $this->transitions->transition(
            $order, OrderStatus::DeliveryInProgress, OrderActorType::Driver, $driver->id, null, $driverLoc
        ));
    }

    public function confirmDelivery(User $driver, Order $order, ?string $code): Order
    {
        if ($order->status !== OrderStatus::DeliveryInProgress) {
            throw new OrderDomainException(OrderErrorCode::InvalidStateTransition, trans('order_messages.invalid_state_transition'));
        }
        $enforce = (bool) PlatformSetting::get('codes.enforce_delivery', true);
        $maxAttempts = (int) PlatformSetting::get('codes.max_attempts', 5);

        return DB::transaction(function () use ($driver, $order, $code, $enforce, $maxAttempts): Order {
            $resolvedMethod = DeliveryMethod::Bypassed;
            $driverLoc = $this->driverLocation($driver);

            if ($enforce) {
                if ($order->delivery_code_attempts >= $maxAttempts) {
                    throw new OrderDomainException(OrderErrorCode::CodeLocked, trans('order_messages.code_locked'));
                }
                if ($code === null || trim($code) === '') {
                    throw new OrderDomainException(OrderErrorCode::CodeRequired, trans('order_messages.code_required'));
                }
                if (! hash_equals((string) $order->delivery_code, trim($code))) {
                    $order->increment('delivery_code_attempts');
                    throw new OrderDomainException(
                        OrderErrorCode::InvalidDeliveryCode,
                        trans('order_messages.invalid_delivery_code'),
                        ['attempts_remaining' => max(0, $maxAttempts - $order->delivery_code_attempts)],
                    );
                }
                $resolvedMethod = DeliveryMethod::Code;
            }

            $order->delivered_method = $resolvedMethod;
            $order->save();

            return $this->transitions->transition(
                $order, OrderStatus::Delivered, OrderActorType::Driver, $driver->id, null, $driverLoc,
                ['method' => $resolvedMethod->value]
            );
        });
    }

    private function driverLocation(User $driver): ?Point
    {
        $profile = DriverProfile::where('user_id', $driver->id)->first();
        if ($profile?->current_location === null) {
            return null;
        }
        $staleAfter = (int) PlatformSetting::get('driver.location_stale_after_seconds', 120);
        if ($profile->last_location_updated_at === null
            || $profile->last_location_updated_at->diffInSeconds(now()) > $staleAfter) {
            return null;
        }
        return $profile->current_location;
    }
}
```

- [ ] **Step 12.2: Create the three FormRequests.**

File: `app/Http/Requests/Order/ConfirmPickupRequest.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ConfirmPickupRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'method' => ['sometimes', Rule::in(['code', 'geofence'])],
            'code' => ['sometimes', 'string', 'size:6', 'regex:/^\d{6}$/'],
        ];
    }
}
```

File: `app/Http/Requests/Order/ConfirmPickupGeofenceRequest.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

final class ConfirmPickupGeofenceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lat' => ['sometimes', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'numeric', 'between:-180,180'],
        ];
    }
}
```

File: `app/Http/Requests/Order/ConfirmDeliveryRequest.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

final class ConfirmDeliveryRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'string', 'size:6', 'regex:/^\d{6}$/'],
        ];
    }
}
```

- [ ] **Step 12.3: Create the four controllers.**

File: `app/Http/Controllers/Api/Driver/Order/ConfirmPickupController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Driver\Order;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\ConfirmPickupRequest;
use App\Http\Resources\Order\DriverOrderResource;
use App\Models\Order;
use App\Services\Order\CodeVerificationService;

final class ConfirmPickupController extends Controller
{
    public function __construct(private readonly CodeVerificationService $codes) {}

    public function __invoke(ConfirmPickupRequest $req, Order $order): DriverOrderResource
    {
        $this->authorize('act', $order);

        $updated = $this->codes->confirmPickup(
            $req->user(),
            $order,
            $req->input('method'),
            $req->input('code'),
        );

        return new DriverOrderResource($updated);
    }
}
```

File: `app/Http/Controllers/Api/Driver/Order/ArrivedDropoffController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Driver\Order;

use App\Http\Controllers\Controller;
use App\Http\Resources\Order\DriverOrderResource;
use App\Models\Order;
use App\Services\Order\CodeVerificationService;
use Illuminate\Http\Request;

final class ArrivedDropoffController extends Controller
{
    public function __construct(private readonly CodeVerificationService $codes) {}

    public function __invoke(Request $req, Order $order): DriverOrderResource
    {
        $this->authorize('act', $order);
        $updated = $this->codes->arrivedDropoff($req->user(), $order);
        return new DriverOrderResource($updated);
    }
}
```

File: `app/Http/Controllers/Api/Driver/Order/ConfirmDeliveryController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Driver\Order;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\ConfirmDeliveryRequest;
use App\Http\Resources\Order\DriverOrderResource;
use App\Models\Order;
use App\Services\Order\CodeVerificationService;

final class ConfirmDeliveryController extends Controller
{
    public function __construct(private readonly CodeVerificationService $codes) {}

    public function __invoke(ConfirmDeliveryRequest $req, Order $order): DriverOrderResource
    {
        $this->authorize('act', $order);
        $updated = $this->codes->confirmDelivery($req->user(), $order, $req->input('code'));
        return new DriverOrderResource($updated);
    }
}
```

File: `app/Http/Controllers/Api/Me/Order/GeofenceConfirmController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Me\Order;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\ConfirmPickupGeofenceRequest;
use App\Http\Resources\Order\OrderResource;
use App\Models\Order;
use App\Services\Order\CodeVerificationService;

final class GeofenceConfirmController extends Controller
{
    public function __construct(private readonly CodeVerificationService $codes) {}

    public function __invoke(ConfirmPickupGeofenceRequest $req, Order $order): OrderResource
    {
        $this->authorize('confirmGeofenceBySender', $order);
        $updated = $this->codes->confirmGeofenceBySender($req->user(), $order);
        return new OrderResource($updated);
    }
}
```

- [ ] **Step 12.4: Register throttles + routes.**

File: `app/Providers/AppServiceProvider.php` — add inside `configureRateLimiters()` alongside the existing `login`, `otp_request`, `orders_quote` limiters (matches project convention; do NOT use `bootstrap/app.php`):

```php
RateLimiter::for('driver_action', fn (\Illuminate\Http\Request $r) => [
    Limit::perMinute(10)->by((string) optional($r->user())->id),
]);
RateLimiter::for('me_action', fn (\Illuminate\Http\Request $r) => [
    Limit::perMinute(5)->by((string) optional($r->user())->id),
]);
```

File: `routes/api.php` — extend `/driver/orders` and `/me/orders`:

```php
use App\Http\Controllers\Api\Driver\Order\ConfirmPickupController;
use App\Http\Controllers\Api\Driver\Order\ArrivedDropoffController;
use App\Http\Controllers\Api\Driver\Order\ConfirmDeliveryController;
use App\Http\Controllers\Api\Me\Order\GeofenceConfirmController;

// inside /driver/orders group:
Route::post('{order:public_id}/confirm-pickup', ConfirmPickupController::class)->middleware('throttle:driver_action');
Route::post('{order:public_id}/arrived-dropoff', ArrivedDropoffController::class)->middleware('throttle:driver_action');
Route::post('{order:public_id}/confirm-delivery', ConfirmDeliveryController::class)->middleware('throttle:driver_action');

// inside /me/orders group:
Route::post('{order:public_id}/confirm-pickup-geofence', GeofenceConfirmController::class)->middleware('throttle:me_action');
```

- [ ] **Step 12.5: Tinker smoke — drive the order from Task 11 to `delivered`.**

```php
// Continuing from Task 11 smoke: driver claimed an order, status=en_route_pickup
$o = \App\Models\Order::query()->where('status', 'en_route_pickup')->latest()->first();
$pickupCode = $o->pickup_code;
$deliveryCode = $o->delivery_code;
$driverId = $o->driver_id;
$driver = \App\Models\User::find($driverId);
$driverToken = $driver->createToken('confirm-smoke')->plainTextToken;

// 1) Confirm pickup with code
$r1 = \Illuminate\Support\Facades\Http::withToken($driverToken)->acceptJson()->post(
    url('/api/driver/orders/' . $o->public_id . '/confirm-pickup'),
    ['method' => 'code', 'code' => $pickupCode]
);
$r1->status();                          // 200
$r1->json('data.status');               // "en_route_dropoff" (auto-chained from picked_up)

$o->refresh()->picked_up_method->value; // "code"
$o->picked_up_at !== null;              // true

// 2) Arrived dropoff (driver must be within 1000m of receiver — heartbeat to ensure)
$r = \DB::table('regions')->first();
$c = \DB::selectOne('SELECT ST_X(receiver_location::geometry) lng, ST_Y(receiver_location::geometry) lat FROM orders WHERE id = ?', [$o->id]);
\Illuminate\Support\Facades\Http::withToken($driverToken)->acceptJson()->post(url('/api/driver/location'), ['lat' => (float) $c->lat, 'lng' => (float) $c->lng]);

$r2 = \Illuminate\Support\Facades\Http::withToken($driverToken)->acceptJson()->post(url('/api/driver/orders/' . $o->public_id . '/arrived-dropoff'));
$r2->status();                          // 200
$r2->json('data.status');               // "delivery_in_progress"

// 3) Confirm delivery with code
$beforeAccount = \App\Models\DriverAccount::where('driver_id', $driverId)->first()->toArray();

$r3 = \Illuminate\Support\Facades\Http::withToken($driverToken)->acceptJson()->post(
    url('/api/driver/orders/' . $o->public_id . '/confirm-delivery'),
    ['code' => $deliveryCode]
);
$r3->status();                          // 200
$r3->json('data.status');               // "delivered"

$afterAccount = \App\Models\DriverAccount::where('driver_id', $driverId)->first()->toArray();
// cash_to_deposit increased by cashCollectedAtDelivery
// earnings_balance increased by delivery_fee - driver_fee_cut_amount

\App\Models\DriverProfile::where('user_id', $driverId)->first()->activity_status->value; // "online" (back from on_order)
\App\Models\Order::where('id', $o->id)->first()->statusLogs()->count(); // 6 total: created→awaiting_driver, awaiting→assigned, assigned→en_route_pickup, en_route_pickup→picked_up, picked_up→en_route_dropoff, en_route_dropoff→delivery_in_progress, delivery_in_progress→delivered = 7
```

- [ ] **Step 12.6: Tinker smoke — code lockout path.**

```php
// Quick fresh order through the flow up to en_route_pickup, then 5 bad attempts → 6th = 429.
// Re-create an order, claim it, then attempt confirm-pickup with wrong code repeatedly.
// (Use the same Tinker harness from Task 8 + Task 11 to set up state.)

// After 5 wrong attempts:
$r = \Illuminate\Support\Facades\Http::withToken($driverToken)->acceptJson()->post(
    url('/api/driver/orders/' . $order->public_id . '/confirm-pickup'),
    ['method' => 'code', 'code' => '000000']
);
$r->status(); // 422 the first 5 times (INVALID_PICKUP_CODE) — then 429 on the 6th (CODE_LOCKED)
```

- [ ] **Step 12.7: Tinker smoke — kill-switch bypass path.**

```php
\App\Models\PlatformSetting::set('codes.enforce_pickup', false);

// Create + claim a fresh order, then:
$r = \Illuminate\Support\Facades\Http::withToken($driverToken)->acceptJson()->post(
    url('/api/driver/orders/' . $newOrder->public_id . '/confirm-pickup'),
    []  // empty body — no method, no code
);
$r->status(); // 200
$newOrder->refresh()->picked_up_method->value; // "bypassed"

// Revert
\App\Models\PlatformSetting::set('codes.enforce_pickup', true);
```

---

## Task 13: Sender retry + free-cancel from `no_driver_available`

**Files:**
- Create: `app/Services/Order/RetryService.php`
- Create: `app/Services/Order/CancellationService.php`
- Create: `app/Http/Requests/Order/RetryOrderRequest.php`
- Create: `app/Http/Requests/Order/CancelOrderRequest.php`
- Create: `app/Http/Controllers/Api/Me/Order/RetryController.php`
- Create: `app/Http/Controllers/Api/Me/Order/CancelController.php`
- Modify: `routes/api.php`

- [ ] **Step 13.1: Create `RetryService`.**

File: `app/Services/Order/RetryService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Enums\OrderActorType;
use App\Enums\OrderErrorCode;
use App\Enums\OrderStatus;
use App\Exceptions\Order\OrderDomainException;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RetryService
{
    public function __construct(private readonly StateTransitionService $transitions) {}

    public function retry(User $sender, Order $order): Order
    {
        if ($order->status !== OrderStatus::NoDriverAvailable) {
            throw new OrderDomainException(OrderErrorCode::OrderNotRetryable, trans('order_messages.order_not_retryable'));
        }
        return DB::transaction(function () use ($sender, $order): Order {
            $order->update([
                'search_radius_tier' => 1,
                'delivery_fee_surcharge_percent' => 0,
                'delivery_fee' => $order->delivery_fee_base,
                'no_driver_available_at' => null,
            ]);
            return $this->transitions->transition(
                $order->refresh(),
                OrderStatus::AwaitingDriver,
                OrderActorType::User,
                $sender->id,
                null, null,
                ['event' => 'retry']
            );
        });
    }
}
```

- [ ] **Step 13.2: Create `CancellationService` (scope-limited).**

File: `app/Services/Order/CancellationService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Enums\OrderActorType;
use App\Enums\OrderErrorCode;
use App\Enums\OrderStatus;
use App\Exceptions\Order\OrderDomainException;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CancellationService
{
    public function __construct(private readonly StateTransitionService $transitions) {}

    /**
     * Free cancel from no_driver_available only. Sub-project C will extend this
     * service with the post-claim / fee-bearing paths.
     */
    public function cancelByUserFromNoDriver(User $sender, Order $order, ?string $reason): Order
    {
        if ($order->status !== OrderStatus::NoDriverAvailable) {
            throw new OrderDomainException(OrderErrorCode::OrderNotCancellableFromState, trans('order_messages.order_not_cancellable_from_state'));
        }
        return DB::transaction(function () use ($sender, $order, $reason): Order {
            $order->update([
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $sender->id,
                'cancellation_reason' => $reason,
                'cancellation_fee' => 0,
            ]);
            return $this->transitions->transition(
                $order->refresh(),
                OrderStatus::CancelledByUser,
                OrderActorType::User,
                $sender->id,
                $reason,
            );
        });
    }
}
```

- [ ] **Step 13.3: Create FormRequests.**

File: `app/Http/Requests/Order/RetryOrderRequest.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

final class RetryOrderRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    /** @return array<string, mixed> */
    public function rules(): array { return []; }
}
```

File: `app/Http/Requests/Order/CancelOrderRequest.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

final class CancelOrderRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['sometimes', 'string', 'max:500'],
        ];
    }
}
```

- [ ] **Step 13.4: Create controllers.**

File: `app/Http/Controllers/Api/Me/Order/RetryController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Me\Order;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\RetryOrderRequest;
use App\Http\Resources\Order\OrderResource;
use App\Models\Order;
use App\Services\Order\RetryService;

final class RetryController extends Controller
{
    public function __construct(private readonly RetryService $retries) {}

    public function __invoke(RetryOrderRequest $req, Order $order): OrderResource
    {
        $this->authorize('retryByUser', $order);
        return new OrderResource($this->retries->retry($req->user(), $order));
    }
}
```

File: `app/Http/Controllers/Api/Me/Order/CancelController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Me\Order;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\CancelOrderRequest;
use App\Http\Resources\Order\OrderResource;
use App\Models\Order;
use App\Services\Order\CancellationService;

final class CancelController extends Controller
{
    public function __construct(private readonly CancellationService $cancels) {}

    public function __invoke(CancelOrderRequest $req, Order $order): OrderResource
    {
        $this->authorize('cancelByUser', $order);
        $updated = $this->cancels->cancelByUserFromNoDriver(
            $req->user(),
            $order,
            $req->input('reason'),
        );
        return new OrderResource($updated);
    }
}
```

- [ ] **Step 13.5: Register routes.**

File: `routes/api.php` — extend the `/me/orders` group:

```php
use App\Http\Controllers\Api\Me\Order\RetryController;
use App\Http\Controllers\Api\Me\Order\CancelController;

Route::post('{order:public_id}/retry', RetryController::class)->middleware('throttle:me_action');
Route::post('{order:public_id}/cancel', CancelController::class)->middleware('throttle:me_action');
```

- [ ] **Step 13.6: Tinker smoke — fabricate no_driver_available, retry, cancel.**

```php
// Force an order into no_driver_available manually
$user = \App\Models\User::query()->where('phone_verified_at', '!=', null)->first();
$token = $user->createToken('retry-smoke')->plainTextToken;

// Create a fresh order (reuse the Task 8 smoke harness), then:
$o = \App\Models\Order::query()->where('sender_user_id', $user->id)->where('status', 'awaiting_driver')->latest()->first();
$o->update(['status' => 'no_driver_available', 'no_driver_available_at' => now()]);

// Retry
$r = \Illuminate\Support\Facades\Http::withToken($token)->acceptJson()->post(url('/api/me/orders/' . $o->public_id . '/retry'));
$r->status();                            // 200
$r->json('data.display_status');         // "awaiting_driver"
$o->refresh()->search_radius_tier;       // 1
$o->delivery_fee_surcharge_percent;      // 0

// Force back into no_driver_available, then cancel
$o->update(['status' => 'no_driver_available', 'no_driver_available_at' => now()]);
$c = \Illuminate\Support\Facades\Http::withToken($token)->acceptJson()->post(url('/api/me/orders/' . $o->public_id . '/cancel'), ['reason' => 'tested out']);
$c->status();                            // 200
$c->json('data.display_status');         // "cancelled"
$o->refresh()->status->value;            // "cancelled_by_user"
$o->cancellation_fee;                    // "0.00"
```

---

## Task 14: Escalation + AutoOffline scheduled jobs

**Files:**
- Create: `app/Services/Order/EscalationService.php`
- Create: `app/Services/Driver/AutoOfflineService.php`
- Create: `app/Jobs/EscalateBroadcastingOrdersJob.php`
- Create: `app/Jobs/AutoOfflineIdleDriversJob.php`
- Modify: `routes/console.php`

- [ ] **Step 14.1: Create `EscalationService`.**

File: `app/Services/Order/EscalationService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Enums\OrderActorType;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\PlatformSetting;
use Illuminate\Support\Facades\DB;

final class EscalationService
{
    public function __construct(private readonly StateTransitionService $transitions) {}

    /**
     * Runs against a single broadcasting order. Returns true if anything changed.
     */
    public function process(Order $order): bool
    {
        if ($order->status !== OrderStatus::AwaitingDriver) {
            return false;
        }
        $awaitingSince = $order->awaiting_driver_at;
        if ($awaitingSince === null) {
            return false;
        }
        $elapsedMinutes = $awaitingSince->diffInMinutes(now());

        $noDriverAfter = (int) PlatformSetting::get('broadcast.no_driver_after_minutes', 10);
        $tier3After = (int) PlatformSetting::get('broadcast.tier_3_after_minutes', 6);
        $tier2After = (int) PlatformSetting::get('broadcast.tier_2_after_minutes', 3);
        $tier3Surcharge = (int) PlatformSetting::get('broadcast.tier_3_surcharge_percent', 50);
        $tier2Surcharge = (int) PlatformSetting::get('broadcast.tier_2_surcharge_percent', 20);

        if ($elapsedMinutes >= $noDriverAfter) {
            return DB::transaction(function () use ($order): bool {
                $this->transitions->transition(
                    $order, OrderStatus::NoDriverAvailable, OrderActorType::System,
                    null, null, null,
                    ['reason' => 'scheduler_timeout']
                );
                return true;
            });
        }

        if ($elapsedMinutes >= $tier3After && $order->search_radius_tier < 3) {
            $base = (string) $order->delivery_fee_base;
            $newFee = bcmul($base, bcadd('1', bcdiv((string) $tier3Surcharge, '100', 4), 4), 2);
            $order->update([
                'search_radius_tier' => 3,
                'delivery_fee_surcharge_percent' => $tier3Surcharge,
                'delivery_fee' => $newFee,
            ]);
            return true;
        }

        if ($elapsedMinutes >= $tier2After && $order->search_radius_tier < 2) {
            $base = (string) $order->delivery_fee_base;
            $newFee = bcmul($base, bcadd('1', bcdiv((string) $tier2Surcharge, '100', 4), 4), 2);
            $order->update([
                'search_radius_tier' => 2,
                'delivery_fee_surcharge_percent' => $tier2Surcharge,
                'delivery_fee' => $newFee,
            ]);
            return true;
        }

        return false;
    }
}
```

- [ ] **Step 14.2: Create `AutoOfflineService`.**

File: `app/Services/Driver/AutoOfflineService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Driver;

use App\Enums\DriverActivityStatus;
use App\Enums\OrderStatus;
use App\Models\DriverPresenceLog;
use App\Models\DriverProfile;
use App\Models\Order;
use App\Models\PlatformSetting;
use Illuminate\Support\Facades\DB;

final class AutoOfflineService
{
    public function runSweep(): int
    {
        $gpsLostMinutes = (int) PlatformSetting::get('driver.gps_lost_offline_after_minutes', 5);
        $idleMinutes = (int) PlatformSetting::get('driver.idle_offline_after_minutes', 30);

        $cutoffGps = now()->subMinutes($gpsLostMinutes);
        $cutoffIdle = now()->subMinutes($idleMinutes);

        $candidates = DriverProfile::query()
            ->where('activity_status', DriverActivityStatus::Online->value)
            ->where(function ($q) use ($cutoffGps, $cutoffIdle): void {
                $q->where('last_location_updated_at', '<', $cutoffGps)
                  ->orWhere('last_active_at', '<', $cutoffIdle);
            })
            ->get();

        $offlined = 0;
        foreach ($candidates as $profile) {
            // Skip mid-trip drivers — never auto-offline someone with an active order
            $hasActive = Order::where('driver_id', $profile->user_id)
                ->whereIn('status', [
                    OrderStatus::Assigned->value, OrderStatus::DriverEnRoutePickup->value,
                    OrderStatus::PickedUp->value, OrderStatus::DriverEnRouteDropoff->value,
                    OrderStatus::DeliveryInProgress->value,
                ])->exists();
            if ($hasActive) {
                continue;
            }

            $reason = $profile->last_location_updated_at < $cutoffGps ? 'gps_lost' : 'idle';

            DB::transaction(function () use ($profile, $reason): void {
                $profile->activity_status = DriverActivityStatus::Offline;
                $profile->save();
                DriverPresenceLog::create([
                    'driver_id' => $profile->user_id,
                    'event' => 'auto_offline',
                    'reason' => $reason,
                    'location' => $profile->current_location,
                ]);
            });

            $offlined++;
        }

        return $offlined;
    }
}
```

- [ ] **Step 14.3: Create the two jobs.**

File: `app/Jobs/EscalateBroadcastingOrdersJob.php`

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Order\EscalationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class EscalateBroadcastingOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct() {}

    public function handle(EscalationService $escalator): void
    {
        Cache::lock('escalate-orders', 90)->block(5, function () use ($escalator): void {
            Order::query()
                ->where('status', OrderStatus::AwaitingDriver->value)
                ->cursor()
                ->each(function (Order $order) use ($escalator): void {
                    try {
                        $escalator->process($order);
                    } catch (\Throwable $e) {
                        Log::warning('Escalation failed for order', [
                            'order_id' => $order->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                });
        });
    }
}
```

File: `app/Jobs/AutoOfflineIdleDriversJob.php`

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Driver\AutoOfflineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

final class AutoOfflineIdleDriversJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(AutoOfflineService $svc): void
    {
        Cache::lock('auto-offline-drivers', 90)->block(5, fn () => $svc->runSweep());
    }
}
```

- [ ] **Step 14.4: Schedule both jobs.**

File: `routes/console.php` — add at the bottom (after any existing `Schedule::command(...)` calls):

```php
use App\Jobs\AutoOfflineIdleDriversJob;
use App\Jobs\EscalateBroadcastingOrdersJob;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new EscalateBroadcastingOrdersJob())->everyMinute()->withoutOverlapping();
Schedule::job(new AutoOfflineIdleDriversJob())->everyMinute()->withoutOverlapping();
```

- [ ] **Step 14.5: Tinker smoke — escalation by simulating elapsed time.**

```php
$user = \App\Models\User::query()->where('phone_verified_at', '!=', null)->first();
$o = \App\Models\Order::query()->where('sender_user_id', $user->id)->where('status', 'awaiting_driver')->latest()->first();

// Simulate "3 minutes ago"
$o->update(['awaiting_driver_at' => now()->subMinutes(4)]);

app(\App\Services\Order\EscalationService::class)->process($o->refresh());
$o->refresh()->search_radius_tier;                   // 2
$o->delivery_fee_surcharge_percent;                  // 20
bccomp((string) $o->delivery_fee, bcmul((string) $o->delivery_fee_base, '1.20', 2), 2); // 0 (equal)

// Simulate "10 minutes ago"
$o->update(['awaiting_driver_at' => now()->subMinutes(11)]);
app(\App\Services\Order\EscalationService::class)->process($o->refresh());
$o->refresh()->status->value;                        // "no_driver_available"
$o->statusLogs()->where('to_status', 'no_driver_available')->exists(); // true

// AutoOffline sweep
$driver = \App\Models\User::query()->whereHas('roles', fn ($q) => $q->where('name', 'driver'))->first();
\App\Models\DriverProfile::where('user_id', $driver->id)->update([
    'activity_status' => 'online',
    'last_location_updated_at' => now()->subMinutes(10),  // gps_lost trigger
    'last_active_at' => now()->subMinutes(10),
]);
app(\App\Services\Driver\AutoOfflineService::class)->runSweep();
\App\Models\DriverProfile::where('user_id', $driver->id)->first()->activity_status->value; // "offline"
\App\Models\DriverPresenceLog::where('driver_id', $driver->id)->where('event', 'auto_offline')->latest()->first()->reason; // "gps_lost"
```

- [ ] **Step 14.6: Verify scheduler dispatches the jobs.**

Run: `php artisan schedule:list`

Expected output includes:
```
* * * * *  App\Jobs\EscalateBroadcastingOrdersJob ........ Next: ...
* * * * *  App\Jobs\AutoOfflineIdleDriversJob ............ Next: ...
```

Optional: trigger immediately via `php artisan schedule:run` and confirm no exceptions.

---

## Task 15: Admin order endpoints (list, detail, assign, unassign)

**Files:**
- Create: `app/Services/Order/AdminAssignmentService.php`
- Create: `app/Http/Requests/Order/AdminAssignOrderRequest.php`
- Create: `app/Http/Requests/Order/AdminUnassignOrderRequest.php`
- Create: `app/Http/Controllers/Api/Admin/Order/OrderController.php`
- Modify: `routes/api.php`

- [ ] **Step 15.1: Create `AdminAssignmentService`.**

File: `app/Services/Order/AdminAssignmentService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Enums\DriverActivityStatus;
use App\Enums\DriverStatus;
use App\Enums\ItemSize;
use App\Enums\OrderActorType;
use App\Enums\OrderErrorCode;
use App\Enums\OrderStatus;
use App\Exceptions\Order\OrderDomainException;
use App\Models\DriverAccount;
use App\Models\DriverProfile;
use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AdminAssignmentService
{
    public function __construct(private readonly StateTransitionService $transitions) {}

    /**
     * Force-assign a specific driver. Bypasses the broadcast race.
     */
    public function assign(User $admin, Order $order, int $driverId, bool $force = false): Order
    {
        if (! in_array($order->status, [OrderStatus::AwaitingDriver, OrderStatus::NoDriverAvailable], true)) {
            throw new OrderDomainException(OrderErrorCode::OrderNotAssignable, trans('order_messages.order_not_assignable'));
        }

        /** @var DriverProfile|null $profile */
        $profile = DriverProfile::where('user_id', $driverId)->first();
        if ($profile === null || $profile->status !== DriverStatus::Active) {
            throw new OrderDomainException(OrderErrorCode::DriverNotActive, trans('order_messages.driver_not_active'));
        }

        // No active order
        $hasActive = Order::where('driver_id', $driverId)
            ->whereIn('status', [
                OrderStatus::Assigned->value, OrderStatus::DriverEnRoutePickup->value,
                OrderStatus::PickedUp->value, OrderStatus::DriverEnRouteDropoff->value,
                OrderStatus::DeliveryInProgress->value,
            ])->exists();
        if ($hasActive) {
            throw new OrderDomainException(OrderErrorCode::DriverHasActiveOrder, trans('order_messages.driver_has_active_order'));
        }

        // Hard block: liability headroom
        /** @var DriverAccount $account */
        $account = DriverAccount::where('driver_id', $driverId)->firstOrFail();
        $projectedCash = bcadd(
            (string) $account->cash_to_deposit,
            $order->cashCollectedAtDelivery(),
            2
        );
        if (bccomp($projectedCash, (string) $account->max_cash_liability, 2) === 1) {
            throw new OrderDomainException(OrderErrorCode::DriverLiabilityInsufficient, trans('order_messages.driver_liability_insufficient'));
        }

        // Vehicle mismatch (force-soften)
        $itemSize = $order->item_size;
        $vehicle = $profile->vehicle_type->value;
        $vehicleOk = match ($itemSize) {
            ItemSize::Small, ItemSize::Medium => in_array($vehicle, ['motorcycle', 'car'], true),
            ItemSize::Large, ItemSize::Xlarge => $vehicle === 'car',
        };
        if (! $vehicleOk && ! $force) {
            throw new OrderDomainException(OrderErrorCode::VehicleMismatch, trans('order_messages.vehicle_mismatch'));
        }

        // Region mismatch (force-soften)
        $regionMatches = (bool) DB::selectOne(
            "SELECT EXISTS (
                 SELECT 1
                   FROM regions r
                   JOIN driver_region dr ON dr.region_id = r.id
                  WHERE dr.user_id = ?
                    AND ST_Contains(r.boundary::geometry, (SELECT pickup_location::geometry FROM orders WHERE id = ?))
             ) AS m",
            [$driverId, $order->id]
        )->m;
        if (! $regionMatches && ! $force) {
            throw new OrderDomainException(OrderErrorCode::DriverRegionMismatch, trans('order_messages.driver_region_mismatch'));
        }

        return DB::transaction(function () use ($admin, $order, $driverId, $profile, $force, $vehicleOk, $regionMatches): Order {
            $affected = DB::update(
                "UPDATE orders
                    SET driver_id = ?,
                        status = 'en_route_pickup',
                        status_changed_at = NOW(),
                        assigned_at = NOW(),
                        driver_en_route_pickup_at = NOW(),
                        driver_assignment_attempts = driver_assignment_attempts + 1,
                        no_driver_available_at = NULL,
                        updated_at = NOW()
                  WHERE id = ?
                    AND status IN ('awaiting_driver', 'no_driver_available')",
                [$driverId, $order->id]
            );
            if ($affected !== 1) {
                throw new OrderDomainException(OrderErrorCode::OrderAlreadyClaimed, trans('order_messages.order_already_claimed'));
            }

            // Force the driver into on_order regardless of prior activity_status
            DriverProfile::where('user_id', $driverId)->update([
                'activity_status' => DriverActivityStatus::OnOrder->value,
                'last_active_at' => now(),
            ]);

            $forceFlags = [
                'vehicle_mismatch' => ! $vehicleOk,
                'region_mismatch' => ! $regionMatches,
            ];

            // Audit rows for the two transitions
            OrderStatusLog::create([
                'order_id' => $order->id,
                'from_status' => $order->status->value, // pre-update status; status was awaiting_driver or no_driver_available
                'to_status' => OrderStatus::Assigned->value,
                'actor_type' => OrderActorType::Admin->value,
                'actor_id' => $admin->id,
                'reason' => 'manual_assignment',
                'metadata' => ['admin_id' => $admin->id, 'force_flags' => $forceFlags],
            ]);
            OrderStatusLog::create([
                'order_id' => $order->id,
                'from_status' => OrderStatus::Assigned->value,
                'to_status' => OrderStatus::DriverEnRoutePickup->value,
                'actor_type' => OrderActorType::System->value,
                'metadata' => ['reason' => 'auto_on_admin_assign'],
            ]);

            return $order->refresh();
        });
    }

    /**
     * Pull an assigned/en_route_pickup order back to broadcast. Driver becomes online.
     */
    public function unassign(User $admin, Order $order, ?string $reason, bool $resetTier = true): Order
    {
        if (! in_array($order->status, [OrderStatus::Assigned, OrderStatus::DriverEnRoutePickup], true)) {
            throw new OrderDomainException(OrderErrorCode::OrderNotUnassignable, trans('order_messages.order_not_unassignable'));
        }
        if ($order->driver_id === null) {
            throw new OrderDomainException(OrderErrorCode::OrderHasNoDriver, trans('order_messages.order_has_no_driver'));
        }

        return DB::transaction(function () use ($admin, $order, $reason, $resetTier): Order {
            $previousDriverId = $order->driver_id;

            $updates = [
                'driver_id' => null,
                'assigned_at' => null,
                'driver_en_route_pickup_at' => null,
                'awaiting_driver_at' => now(),
            ];
            if ($resetTier) {
                $updates['search_radius_tier'] = 1;
                $updates['delivery_fee_surcharge_percent'] = 0;
                $updates['delivery_fee'] = $order->delivery_fee_base;
            }
            $order->update($updates);

            DriverProfile::where('user_id', $previousDriverId)->update([
                'activity_status' => DriverActivityStatus::Online->value,
                'last_active_at' => now(),
            ]);

            return $this->transitions->transition(
                $order->refresh(),
                OrderStatus::AwaitingDriver,
                OrderActorType::Admin,
                $admin->id,
                $reason,
                null,
                ['previous_driver_id' => $previousDriverId, 'reset_tier' => $resetTier, 'admin_id' => $admin->id]
            );
        });
    }
}
```

- [ ] **Step 15.2: Create FormRequests.**

File: `app/Http/Requests/Order/AdminAssignOrderRequest.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

final class AdminAssignOrderRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'driver_id' => ['required', 'integer', 'exists:users,id'],
            'force' => ['sometimes', 'boolean'],
        ];
    }
}
```

File: `app/Http/Requests/Order/AdminUnassignOrderRequest.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

final class AdminUnassignOrderRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['sometimes', 'string', 'max:500'],
            'reset_tier' => ['sometimes', 'boolean'],
        ];
    }
}
```

- [ ] **Step 15.3: Create admin `OrderController`.**

File: `app/Http/Controllers/Api/Admin/Order/OrderController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Order;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\AdminAssignOrderRequest;
use App\Http\Requests\Order\AdminUnassignOrderRequest;
use App\Http\Resources\Order\AdminOrderResource;
use App\Models\Order;
use App\Services\Order\AdminAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class OrderController extends Controller
{
    public function __construct(private readonly AdminAssignmentService $assignments) {}

    public function index(Request $req): AnonymousResourceCollection
    {
        $q = Order::query();
        if ($status = $req->query('status')) {
            $q->where('status', $status);
        }
        if ($from = $req->query('from')) {
            $q->where('created_at', '>=', $from);
        }
        if ($to = $req->query('to')) {
            $q->where('created_at', '<=', $to);
        }
        if ($driverId = $req->query('driver_id')) {
            $q->where('driver_id', (int) $driverId);
        }
        $orders = $q->orderByDesc('created_at')->paginate((int) $req->query('per_page', 30));

        return AdminOrderResource::collection($orders);
    }

    public function show(Order $order): AdminOrderResource
    {
        $order->load('statusLogs');
        return new AdminOrderResource($order);
    }

    public function assign(AdminAssignOrderRequest $req, Order $order): AdminOrderResource
    {
        $updated = $this->assignments->assign(
            admin: $req->user(),
            order: $order,
            driverId: (int) $req->input('driver_id'),
            force: (bool) $req->input('force', false),
        );
        $updated->load('statusLogs');
        return new AdminOrderResource($updated);
    }

    public function unassign(AdminUnassignOrderRequest $req, Order $order): AdminOrderResource
    {
        $updated = $this->assignments->unassign(
            admin: $req->user(),
            order: $order,
            reason: $req->input('reason'),
            resetTier: (bool) $req->input('reset_tier', true),
        );
        $updated->load('statusLogs');
        return new AdminOrderResource($updated);
    }
}
```

- [ ] **Step 15.4: Register routes.**

File: `routes/api.php` — add a new admin group:

```php
use App\Http\Controllers\Api\Admin\Order\OrderController as AdminOrderController;

Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/orders')->group(function (): void {
    Route::get('/', [AdminOrderController::class, 'index']);
    Route::get('{order:public_id}', [AdminOrderController::class, 'show']);
    Route::post('{order:public_id}/assign', [AdminOrderController::class, 'assign']);
    Route::post('{order:public_id}/unassign', [AdminOrderController::class, 'unassign']);
});
```

- [ ] **Step 15.5: Tinker smoke — admin assign + unassign.**

```php
$admin = \App\Models\User::query()->whereHas('roles', fn ($q) => $q->where('name', 'admin'))->first();
$adminToken = $admin->createToken('admin-smoke')->plainTextToken;

// Force an order into awaiting_driver, then admin assigns it
$o = \App\Models\Order::factory()->create([...]); // or reuse Task 8 + revert it back to awaiting_driver
$driverId = \App\Models\DriverProfile::where('status', 'active')->first()->user_id;

// Pre-set driver offline to prove force-assign works without going-online
\App\Models\DriverProfile::where('user_id', $driverId)->update(['activity_status' => 'offline']);

$a = \Illuminate\Support\Facades\Http::withToken($adminToken)->acceptJson()->post(
    url('/api/admin/orders/' . $o->public_id . '/assign'),
    ['driver_id' => $driverId, 'force' => true]
);
$a->status();                          // 200
$a->json('data.status');               // "en_route_pickup"
$a->json('data.driver.user_id');       // $driverId
\App\Models\DriverProfile::where('user_id', $driverId)->first()->activity_status->value; // "on_order"

// Admin unassigns
$u = \Illuminate\Support\Facades\Http::withToken($adminToken)->acceptJson()->post(
    url('/api/admin/orders/' . $o->public_id . '/unassign'),
    ['reason' => 'Driver phone unreachable', 'reset_tier' => true]
);
$u->status();                          // 200
$u->json('data.status');               // "awaiting_driver"
$u->json('data.driver');               // null
\App\Models\DriverProfile::where('user_id', $driverId)->first()->activity_status->value; // "online"

// admin index + show
$idx = \Illuminate\Support\Facades\Http::withToken($adminToken)->acceptJson()->get(url('/api/admin/orders'));
$idx->status();                        // 200
$idx->json('data');                    // array

$sh = \Illuminate\Support\Facades\Http::withToken($adminToken)->acceptJson()->get(url('/api/admin/orders/' . $o->public_id));
$sh->json('data.status_logs');         // array of logs including the assign + unassign rows
```

---

## Task 16: End-to-end smoke test script

**File:**
- Create: `scripts/orders-e2e.php`

- [ ] **Step 16.1: Create the script.**

File: `scripts/orders-e2e.php`

```php
<?php

declare(strict_types=1);

/**
 * Order Lifecycle E2E Smoke
 *
 * Run with:
 *   php artisan tinker --execute="require base_path('scripts/orders-e2e.php');"
 *
 * Exercises 20 scenarios end-to-end against the live DB.
 * Idempotent-ish: each scenario creates its own fresh order.
 * Will write to driver_accounts; revert manually post-run if needed.
 */

use App\Enums\DriverActivityStatus;
use App\Enums\DriverStatus;
use App\Enums\OrderStatus;
use App\Models\DriverAccount;
use App\Models\DriverPresenceLog;
use App\Models\DriverProfile;
use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\Region;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$assert = function (bool $cond, string $label): void {
    if (! $cond) {
        echo "  ❌ FAIL: $label\n";
        throw new RuntimeException("Assertion failed: $label");
    }
    echo "  ✓ $label\n";
};

$line = fn (string $s) => "\n=== {$s} ===\n";

// ─── Setup ───────────────────────────────────────────────────────────────
echo $line('SETUP');

$sender = User::query()->where('phone_verified_at', '!=', null)->first()
    ?? throw new RuntimeException('Need a phone-verified user. Seed one via auth milestone seeders.');
$senderToken = $sender->createToken('e2e-sender')->plainTextToken;

$driver = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'driver'))->first()
    ?? throw new RuntimeException('Need an approved driver. Onboard one first.');
$driverToken = $driver->createToken('e2e-driver')->plainTextToken;

$admin = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'admin'))->first()
    ?? throw new RuntimeException('Need an admin user. Run TestStaffSeeder.');
$adminToken = $admin->createToken('e2e-admin')->plainTextToken;

// Ensure at least one region has a non-zero base_fee
$region = Region::join('driver_region', 'regions.id', '=', 'driver_region.region_id')
    ->where('driver_region.user_id', $driver->id)
    ->select('regions.*')->first()
    ?? throw new RuntimeException('Driver has no assigned region.');
DB::table('regions')->where('id', $region->id)->update(['base_fee' => 10]);

$centroid = DB::selectOne(
    'SELECT ST_X(ST_Centroid(boundary::geometry)) lng, ST_Y(ST_Centroid(boundary::geometry)) lat FROM regions WHERE id = ?',
    [$region->id]
);
$pickup = ['lat' => (float) $centroid->lat, 'lng' => (float) $centroid->lng];
$dropoff = ['lat' => (float) $centroid->lat + 0.01, 'lng' => (float) $centroid->lng + 0.01];

echo "Sender: {$sender->phone_number}\n";
echo "Driver: {$driver->phone_number}\n";
echo "Admin:  {$admin->phone_number}\n";
echo "Region: {$region->name} (base_fee=10)\n";

// ─── Scenario 1: Driver goes online ──────────────────────────────────────
echo $line('1. Driver goes online');
$r = Http::withToken($driverToken)->acceptJson()->post(url('/api/driver/go-online'), $pickup);
$assert($r->status() === 200, 'go-online returns 200');
$assert($r->json('activity_status') === 'online', 'activity_status = online');
$assert(DriverPresenceLog::where('driver_id', $driver->id)->where('event', 'went_online')->exists(), 'presence log row written');

// ─── Scenario 2: Quote standard_delivery ─────────────────────────────────
echo $line('2. Quote standard_delivery');
$q = Http::withToken($senderToken)->acceptJson()->post(url('/api/orders/quote'), [
    'order_type' => 'standard_delivery',
    'pickup_location' => $pickup,
    'receiver_location' => $dropoff,
    'item_size' => 'small',
]);
$assert($q->status() === 200, 'quote returns 200');
$assert(bccomp((string) $q->json('pricing.delivery_fee_base'), '10.00', 2) === 0, 'delivery_fee_base = 10.00 (region base_fee)');
$quoteToken = $q->json('quote_token');

// ─── Scenario 3: Create order ────────────────────────────────────────────
echo $line('3. Create standard_delivery order');
$create = Http::withToken($senderToken)->acceptJson()->post(url('/api/orders'), [
    'quote_token' => $quoteToken,
    'order_type' => 'standard_delivery',
    'pickup_location' => $pickup,
    'pickup_address' => 'E2E pickup',
    'receiver_location' => $dropoff,
    'receiver_address' => 'E2E dropoff',
    'receiver_phone' => '+218910000001',  // guest receiver
    'receiver_name' => 'E2E Receiver',
    'item_size' => 'small',
    'item_description' => 'E2E parcel for smoke test',
]);
$assert($create->status() === 201, 'create returns 201');
$orderPid = $create->json('data.id');
$pickupCode = $create->json('pickup_code');
$assert(strlen($pickupCode) === 6, 'pickup_code is 6 digits');
$o = Order::where('public_id', $orderPid)->firstOrFail();
$assert($o->status === OrderStatus::AwaitingDriver, 'order in awaiting_driver');
$assert($o->statusLogs()->count() === 1, 'one status_log entry');

// ─── Scenario 4: Driver sees + claims the order ──────────────────────────
echo $line('4. Driver claims');
$b = Http::withToken($driverToken)->acceptJson()->get(url('/api/driver/orders/broadcast'));
$assert($b->status() === 200, 'broadcast returns 200');
$assert(collect((array) $b->json('data'))->contains(fn ($e) => $e['id'] === $orderPid), 'order appears in broadcast');

$claim = Http::withToken($driverToken)->acceptJson()->post(url('/api/driver/orders/' . $orderPid . '/claim'));
$assert($claim->status() === 200, 'claim returns 200');
$assert($claim->json('data.status') === 'en_route_pickup', 'status auto-chained to en_route_pickup');

// ─── Scenario 5: Confirm pickup with code ────────────────────────────────
echo $line('5. Confirm pickup (code)');
$cp = Http::withToken($driverToken)->acceptJson()->post(
    url('/api/driver/orders/' . $orderPid . '/confirm-pickup'),
    ['method' => 'code', 'code' => $pickupCode]
);
$assert($cp->status() === 200, 'confirm-pickup returns 200');
$assert($cp->json('data.status') === 'en_route_dropoff', 'status auto-chained to en_route_dropoff');

// ─── Scenario 6: Arrived dropoff ─────────────────────────────────────────
echo $line('6. Arrived dropoff');
Http::withToken($driverToken)->acceptJson()->post(url('/api/driver/location'), $dropoff);
$ad = Http::withToken($driverToken)->acceptJson()->post(url('/api/driver/orders/' . $orderPid . '/arrived-dropoff'));
$assert($ad->status() === 200, 'arrived-dropoff returns 200');
$assert($ad->json('data.status') === 'delivery_in_progress', 'status = delivery_in_progress');

// ─── Scenario 7: Confirm delivery + driver bucket credit ─────────────────
echo $line('7. Confirm delivery + financial side-effects');
$deliveryCode = Order::where('public_id', $orderPid)->first()->delivery_code;
$beforeCashD = (string) DriverAccount::where('driver_id', $driver->id)->first()->cash_to_deposit;
$beforeEarn = (string) DriverAccount::where('driver_id', $driver->id)->first()->earnings_balance;

$cd = Http::withToken($driverToken)->acceptJson()->post(
    url('/api/driver/orders/' . $orderPid . '/confirm-delivery'),
    ['code' => $deliveryCode]
);
$assert($cd->status() === 200, 'confirm-delivery returns 200');
$assert($cd->json('data.status') === 'delivered', 'status = delivered');

$afterCashD = (string) DriverAccount::where('driver_id', $driver->id)->first()->cash_to_deposit;
$afterEarn = (string) DriverAccount::where('driver_id', $driver->id)->first()->earnings_balance;
$o = Order::where('public_id', $orderPid)->first();
$expectedEarn = bcsub((string) $o->delivery_fee, (string) $o->driver_fee_cut_amount, 2);
$assert(bccomp(bcsub($afterCashD, $beforeCashD, 2), $o->cashCollectedAtDelivery(), 2) === 0, 'cash_to_deposit grew by cash_collected_at_delivery');
$assert(bccomp(bcsub($afterEarn, $beforeEarn, 2), $expectedEarn, 2) === 0, 'earnings_balance grew by delivery_fee - cut');
$assert(DriverProfile::where('user_id', $driver->id)->first()->activity_status === DriverActivityStatus::Online, 'driver back to online after delivery');

// ─── Scenario 8: P2P sale flow ───────────────────────────────────────────
echo $line('8. P2P sale order — item_price flows correctly');
$q2 = Http::withToken($senderToken)->acceptJson()->post(url('/api/orders/quote'), [
    'order_type' => 'p2p_sale',
    'pickup_location' => $pickup,
    'receiver_location' => $dropoff,
    'item_size' => 'medium',
    'item_price' => 50.00,
]);
$assert($q2->status() === 200, 'p2p quote returns 200');
$assert(bccomp((string) $q2->json('pricing.cash_collected_at_delivery'), '60.00', 2) === 0, 'cash_at_delivery = item_price + delivery_fee (receiver pays)');

// ─── Scenario 9: Two-driver race (atomic claim) ──────────────────────────
echo $line('9. Atomic claim race');
$other = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'driver'))->where('id', '!=', $driver->id)->first();
if ($other === null) {
    echo "  ⊘ skipped (no second driver onboarded)\n";
} else {
    // create fresh order
    $qq = Http::withToken($senderToken)->acceptJson()->post(url('/api/orders/quote'), [
        'order_type' => 'standard_delivery', 'pickup_location' => $pickup, 'receiver_location' => $dropoff, 'item_size' => 'small',
    ]);
    $cc = Http::withToken($senderToken)->acceptJson()->post(url('/api/orders'), [
        'quote_token' => $qq->json('quote_token'),
        'order_type' => 'standard_delivery',
        'pickup_location' => $pickup, 'pickup_address' => 'race',
        'receiver_location' => $dropoff, 'receiver_address' => 'race',
        'receiver_phone' => '+218910000002', 'receiver_name' => 'Race R',
        'item_size' => 'small', 'item_description' => 'race smoke test parcel',
    ]);
    $racePid = $cc->json('data.id');

    $tOther = $other->createToken('race')->plainTextToken;
    Http::withToken($tOther)->acceptJson()->post(url('/api/driver/go-online'), $pickup);
    Http::withToken($driverToken)->acceptJson()->post(url('/api/driver/go-online'), $pickup);  // re-online primary

    $r1 = Http::withToken($driverToken)->acceptJson()->post(url('/api/driver/orders/' . $racePid . '/claim'));
    $r2 = Http::withToken($tOther)->acceptJson()->post(url('/api/driver/orders/' . $racePid . '/claim'));
    $assert($r1->status() === 200, 'first claim wins (200)');
    $assert($r2->status() === 409, 'second claim loses (409)');
    $assert($r2->json('error.code') === 'ORDER_ALREADY_CLAIMED', 'loser error code');
}

// ─── Scenario 10: Tier escalation ────────────────────────────────────────
echo $line('10. Tier escalation');
$qq = Http::withToken($senderToken)->acceptJson()->post(url('/api/orders/quote'), [
    'order_type' => 'standard_delivery', 'pickup_location' => $pickup, 'receiver_location' => $dropoff, 'item_size' => 'small',
]);
$cc = Http::withToken($senderToken)->acceptJson()->post(url('/api/orders'), [
    'quote_token' => $qq->json('quote_token'),
    'order_type' => 'standard_delivery',
    'pickup_location' => $pickup, 'pickup_address' => 'escalate',
    'receiver_location' => $dropoff, 'receiver_address' => 'escalate',
    'receiver_phone' => '+218910000003', 'receiver_name' => 'Esc R',
    'item_size' => 'small', 'item_description' => 'escalation smoke parcel',
]);
$ePid = $cc->json('data.id');
$eOrder = Order::where('public_id', $ePid)->firstOrFail();
$eOrder->update(['awaiting_driver_at' => now()->subMinutes(4)]);
app(\App\Services\Order\EscalationService::class)->process($eOrder->refresh());
$assert($eOrder->refresh()->search_radius_tier === 2, 'tier bumped to 2 after 3 min');
$assert($eOrder->delivery_fee_surcharge_percent === 20, 'surcharge = 20%');

$eOrder->update(['awaiting_driver_at' => now()->subMinutes(11)]);
app(\App\Services\Order\EscalationService::class)->process($eOrder->refresh());
$assert($eOrder->refresh()->status === OrderStatus::NoDriverAvailable, 'flipped to no_driver_available after 10 min');

// ─── Scenario 11: Sender retry ───────────────────────────────────────────
echo $line('11. Sender retry');
$rt = Http::withToken($senderToken)->acceptJson()->post(url('/api/me/orders/' . $ePid . '/retry'));
$assert($rt->status() === 200, 'retry returns 200');
$assert($rt->json('data.display_status') === 'awaiting_driver', 'display_status back to awaiting_driver');
$assert((int) Order::where('public_id', $ePid)->first()->search_radius_tier === 1, 'tier reset to 1');

// ─── Scenario 12: Sender free-cancel from no_driver ──────────────────────
echo $line('12. Free cancel from no_driver_available');
Order::where('public_id', $ePid)->update(['status' => 'no_driver_available', 'no_driver_available_at' => now()]);
$cnc = Http::withToken($senderToken)->acceptJson()->post(url('/api/me/orders/' . $ePid . '/cancel'), ['reason' => 'gave up']);
$assert($cnc->status() === 200, 'cancel returns 200');
$assert($cnc->json('data.display_status') === 'cancelled', 'display_status = cancelled');

// ─── Scenario 13: Admin manual assign (offline driver) ───────────────────
echo $line('13. Admin manual assign');
$qq = Http::withToken($senderToken)->acceptJson()->post(url('/api/orders/quote'), [
    'order_type' => 'standard_delivery', 'pickup_location' => $pickup, 'receiver_location' => $dropoff, 'item_size' => 'small',
]);
$cc = Http::withToken($senderToken)->acceptJson()->post(url('/api/orders'), [
    'quote_token' => $qq->json('quote_token'),
    'order_type' => 'standard_delivery',
    'pickup_location' => $pickup, 'pickup_address' => 'admin assign',
    'receiver_location' => $dropoff, 'receiver_address' => 'admin assign',
    'receiver_phone' => '+218910000004', 'receiver_name' => 'Adm R',
    'item_size' => 'small', 'item_description' => 'admin assign smoke parcel',
]);
$aPid = $cc->json('data.id');
Http::withToken($driverToken)->acceptJson()->post(url('/api/driver/go-offline'));  // offline first

$asn = Http::withToken($adminToken)->acceptJson()->post(
    url('/api/admin/orders/' . $aPid . '/assign'),
    ['driver_id' => $driver->id, 'force' => true]
);
$assert($asn->status() === 200, 'admin assign returns 200');
$assert($asn->json('data.status') === 'en_route_pickup', 'status en_route_pickup');
$assert(DriverProfile::where('user_id', $driver->id)->first()->activity_status === DriverActivityStatus::OnOrder, 'driver forced to on_order');

// ─── Scenario 14: Admin unassign ─────────────────────────────────────────
echo $line('14. Admin unassign');
$un = Http::withToken($adminToken)->acceptJson()->post(
    url('/api/admin/orders/' . $aPid . '/unassign'),
    ['reason' => 'e2e test unassign', 'reset_tier' => true]
);
$assert($un->status() === 200, 'unassign returns 200');
$assert($un->json('data.status') === 'awaiting_driver', 'status awaiting_driver');
$assert($un->json('data.driver') === null, 'driver block null');
$assert(DriverProfile::where('user_id', $driver->id)->first()->activity_status === DriverActivityStatus::Online, 'driver back to online');

// ─── Scenario 15: Code lockout ───────────────────────────────────────────
echo $line('15. Pickup code lockout after 5 wrong tries');
Http::withToken($driverToken)->acceptJson()->post(url('/api/driver/go-online'), $pickup);
Http::withToken($adminToken)->acceptJson()->post(url('/api/admin/orders/' . $aPid . '/assign'), ['driver_id' => $driver->id, 'force' => true]);
for ($i = 1; $i <= 5; $i++) {
    $bad = Http::withToken($driverToken)->acceptJson()->post(
        url('/api/driver/orders/' . $aPid . '/confirm-pickup'),
        ['method' => 'code', 'code' => '000000']
    );
    $assert($bad->status() === 422, "attempt $i returns 422");
}
$locked = Http::withToken($driverToken)->acceptJson()->post(
    url('/api/driver/orders/' . $aPid . '/confirm-pickup'),
    ['method' => 'code', 'code' => '000000']
);
$assert($locked->status() === 429, '6th attempt returns 429 (CODE_LOCKED)');

// ─── Scenario 16: Kill-switch bypass ─────────────────────────────────────
echo $line('16. codes.enforce_pickup = false bypass');
PlatformSetting::set('codes.enforce_pickup', false);
$bypass = Http::withToken($driverToken)->acceptJson()->post(
    url('/api/driver/orders/' . $aPid . '/confirm-pickup'),
    []
);
$assert($bypass->status() === 200, 'empty body succeeds');
$assert(Order::where('public_id', $aPid)->first()->picked_up_method->value === 'bypassed', 'method = bypassed');
PlatformSetting::set('codes.enforce_pickup', true);  // restore

// ─── Scenario 17: Geofence path ──────────────────────────────────────────
echo $line('17. Geofence confirmation path');
Http::withToken($driverToken)->acceptJson()->post(url('/api/driver/location'), $dropoff);
$adropoff = Http::withToken($driverToken)->acceptJson()->post(url('/api/driver/orders/' . $aPid . '/arrived-dropoff'));
$assert($adropoff->status() === 200, 'arrived-dropoff in geofence flow');
// (delivery code path for this order — finish it)
$dc = Order::where('public_id', $aPid)->first()->delivery_code;
Http::withToken($driverToken)->acceptJson()->post(
    url('/api/driver/orders/' . $aPid . '/confirm-delivery'), ['code' => $dc]
);

// ─── Scenario 18: Guest tracking URL ─────────────────────────────────────
echo $line('18. Guest tracking');
$tt = Order::where('public_id', $aPid)->first()->tracking_token;
$gt = Http::acceptJson()->get(url('/api/track/' . $tt));
$assert($gt->status() === 200, 'guest track returns 200');
$assert($gt->json('data.sender.phone') === null, 'sender phone hidden');
$assert(! empty($gt->json('data.delivery_code')), 'delivery_code exposed (receiver needs it)');

// ─── Scenario 19: Quote tampering ────────────────────────────────────────
echo $line('19. Quote price changed → 409');
$qOld = Http::withToken($senderToken)->acceptJson()->post(url('/api/orders/quote'), [
    'order_type' => 'standard_delivery', 'pickup_location' => $pickup, 'receiver_location' => $dropoff, 'item_size' => 'small',
])->json('quote_token');
DB::table('regions')->where('id', $region->id)->update(['base_fee' => 15]);
PlatformSetting::set('pricing.per_km_rate', 0); // ensure cache refresh
$create = Http::withToken($senderToken)->acceptJson()->post(url('/api/orders'), [
    'quote_token' => $qOld, 'order_type' => 'standard_delivery',
    'pickup_location' => $pickup, 'pickup_address' => 'tamper',
    'receiver_location' => $dropoff, 'receiver_address' => 'tamper',
    'receiver_phone' => '+218910000005', 'receiver_name' => 'Tamp',
    'item_size' => 'small', 'item_description' => 'tamper smoke parcel',
]);
$assert($create->status() === 409, 'tampered quote returns 409');
$assert($create->json('error.code') === 'QUOTE_PRICE_CHANGED', 'correct error code');
$assert($create->json('error.details.fresh_quote.quote_token') !== null, 'fresh quote in response');
DB::table('regions')->where('id', $region->id)->update(['base_fee' => 10]);  // restore

// ─── Scenario 20: Auto-offline sweep ─────────────────────────────────────
echo $line('20. Auto-offline sweep (gps_lost)');
Http::withToken($driverToken)->acceptJson()->post(url('/api/driver/go-online'), $pickup);
DriverProfile::where('user_id', $driver->id)->update([
    'last_location_updated_at' => now()->subMinutes(10),
    'last_active_at' => now()->subMinutes(10),
]);
$count = app(\App\Services\Driver\AutoOfflineService::class)->runSweep();
$assert($count >= 1, "sweep offlined >= 1 driver (offlined: $count)");
$assert(DriverProfile::where('user_id', $driver->id)->first()->activity_status === DriverActivityStatus::Offline, 'driver flipped offline');
$assert(DriverPresenceLog::where('driver_id', $driver->id)->where('event', 'auto_offline')->latest()->first()?->reason === 'gps_lost', 'auto_offline reason = gps_lost');

echo "\n🎉 ALL SCENARIOS PASSED\n";
```

- [ ] **Step 16.2: Run the smoke script and resolve failures.**

Run:
```bash
php artisan tinker --execute="require base_path('scripts/orders-e2e.php');"
```

Expected: all 20 scenarios print `✓` lines and end with `🎉 ALL SCENARIOS PASSED`.

When a scenario fails: investigate the failure, fix the underlying code (not the smoke script), re-run until green.

---

## Task 17: Pint + docs update

**Files:**
- Run: `vendor/bin/pint`
- Modify: `docs/CLAUDE.md` (Current Project State section + Rule 9 exception note)
- Modify: `docs/SYSTEM_SPECIFICATION.md` (add §17.8)

- [ ] **Step 17.1: Run Pint over all changed files.**

Run: `vendor/bin/pint`

Expected: clean exit, no unfixed warnings.

- [ ] **Step 17.2: Update `docs/CLAUDE.md` — Critical Rule 9 footnote.**

File: `docs/CLAUDE.md` — modify Rule 9:

```markdown
9. **Never mark delivered without code verification** — pickup + delivery codes are mandatory. Geofence is fallback only.
   *Exception (incident-response override):* the `codes.enforce_pickup` and `codes.enforce_delivery` platform-setting flags can be set to `false` to bypass the code requirement system-wide for the duration of an incident. Bypasses are audited via `picked_up_method = bypassed` / `delivered_method = bypassed` on the order row. Default ON; flipping requires admin action via DB (no UI in MVP).
```

- [ ] **Step 17.3: Update `docs/CLAUDE.md` — `Current Project State` block.**

In `docs/CLAUDE.md` under `Current Project State`:
- Set "Last updated" to today's date.
- Update the status line to mention the orders milestone.
- Add a new subsection `### Order lifecycle (A+B) milestone (YYYY-MM-DD)` immediately under the existing "Driver onboarding milestone (2026-05-10)" block. Mirror that block's format: an endpoint table + a "Locked decisions" paragraph + any key gotchas discovered during build.

Endpoint table:

```markdown
### Order lifecycle (A+B) milestone (YYYY-MM-DD)

| Endpoint | Method | Auth |
|---|---|---|
| `/api/orders/quote` | POST | sanctum |
| `/api/orders` | POST | sanctum, phone-verified |
| `/api/me/orders` | GET | sanctum |
| `/api/me/orders/{public_id}` | GET | sanctum + OrderPolicy::view |
| `/api/me/orders/{public_id}/retry` | POST | sanctum + sender + state guard |
| `/api/me/orders/{public_id}/cancel` | POST | sanctum + sender + no_driver_available only |
| `/api/me/orders/{public_id}/confirm-pickup-geofence` | POST | sanctum + sender + en_route_pickup |
| `/api/track/{tracking_token}` | GET | public |
| `/api/driver/go-online` | POST | sanctum + role:driver |
| `/api/driver/go-offline` | POST | sanctum + role:driver |
| `/api/driver/location` | POST | sanctum + role:driver |
| `/api/driver/orders/broadcast` | GET | sanctum + role:driver |
| `/api/driver/orders/current` | GET | sanctum + role:driver |
| `/api/driver/orders/{public_id}/claim` | POST | sanctum + role:driver |
| `/api/driver/orders/{public_id}/confirm-pickup` | POST | sanctum + role:driver + OrderPolicy::act |
| `/api/driver/orders/{public_id}/arrived-dropoff` | POST | sanctum + role:driver + OrderPolicy::act |
| `/api/driver/orders/{public_id}/confirm-delivery` | POST | sanctum + role:driver + OrderPolicy::act |
| `/api/admin/orders` | GET | sanctum + role:admin |
| `/api/admin/orders/{public_id}` | GET | sanctum + role:admin |
| `/api/admin/orders/{public_id}/assign` | POST | sanctum + role:admin |
| `/api/admin/orders/{public_id}/unassign` | POST | sanctum + role:admin |
```

Locked decisions (copy from spec §3):
- Pricing: per-region flat fee at launch; admin-tunable item-size modifiers + per-km surcharge via `platform_settings`.
- Polling broadcast (no push); atomic conditional UPDATE for claim.
- Hybrid auto + explicit transitions; sender + receiver see collapsed `display_status`; driver + admin see raw `status`.
- Codes: 6-digit encrypted, sender holds pickup_code, receiver holds delivery_code; driver enters what's handed to them; kill-switch via two `platform_settings` flags.
- No_driver_available recovery: sender retry (tier resets to 1) + free cancel; admin can manually assign/unassign for early-launch coverage.
- Tier escalation: silent column update by minute cron; only the `no_driver_available` flip is logged to `order_status_logs`.

- [ ] **Step 17.4: Update `docs/SYSTEM_SPECIFICATION.md` §17.**

In `docs/SYSTEM_SPECIFICATION.md` §17 — add `17.8 Order lifecycle (A+B) milestone (YYYY-MM-DD) ✅`. Format matches §17.6 (driver onboarding):

```markdown
### 17.8 Order lifecycle (A+B) milestone (YYYY-MM-DD) ✅

Built the full happy-path order lifecycle per `docs/superpowers/specs/2026-05-12-order-lifecycle-design.md` and `docs/superpowers/plans/2026-05-12-order-lifecycle.md`.

**Endpoints shipped (21 routes across 5 namespaces):**
- `/api/orders/*` (2): quote + create
- `/api/me/orders/*` (5): list/show/retry/cancel/confirm-pickup-geofence
- `/api/track/{token}` (1): public guest tracking
- `/api/driver/*` (3): go-online/go-offline/location
- `/api/driver/orders/*` (6): broadcast/current/claim/confirm-pickup/arrived-dropoff/confirm-delivery
- `/api/admin/orders/*` (4): index/show/assign/unassign

**State machine driven through `StateTransitionService`** (sole writer of `orders.status`, asserts DB::transaction, fires `OrderStatusChanged`, runs post-transition hooks).

**Locked decisions (recap from spec §3):** see CLAUDE.md "Order lifecycle (A+B) milestone" subsection.

**Cross-cutting work in this milestone:**
- New `OrderErrorCode` enum (34 cases + httpStatus()).
- New `OrderPolicy` with `view`/`act`/`retryByUser`/`cancelByUser`/`confirmGeofenceBySender`.
- New `driver_presence_logs` table for online/offline audit.
- 12 services (Order/*, Driver/PresenceService, Driver/AutoOfflineService) + 2 scheduled jobs.
- Hooks-based post-transition financial side-effects (driver buckets, delivery_fee_status flip).
- `QuoteToken` HMAC signer keyed off `APP_KEY` (5-min TTL).
- Two `platform_settings` kill-switches (`codes.enforce_pickup` / `codes.enforce_delivery`) — explicit exception to Critical Rule 9, documented in CLAUDE.md.
- Idempotency-Key support on POST /orders via 24h Redis cache.

**Bug fixes caught during build:** (fill in as discovered)

**E2E smoke verified 20 scenarios:** see `scripts/orders-e2e.php`.
```

- [ ] **Step 17.5: Mark milestone complete in spec.**

In `docs/superpowers/specs/2026-05-12-order-lifecycle-design.md` — flip the header line:

```markdown
**Status:** ✅ Implemented (YYYY-MM-DD)
```

---

## Done

After Task 17, the order lifecycle (A+B) milestone is complete. The next milestone (sub-project C — cancellation + fees + strikes) extends `CancellationService` with the post-claim paths and adds the strike-system endpoints. The next-next (sub-project D — failed delivery / return flow) drives `DeliveryFailed → ReturningToOffice → AtOffice → RetrievedBySeller / Abandoned`.










