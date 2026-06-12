# Merchant Deliveries Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the `merchant_delivery` order type end to end — admins onboard businesses as merchants; active merchants quote + create shop→customer orders that flow through the existing order/driver/settlement pipeline.

**Architecture:** Approach A from the spec — dedicated `admin/merchants/*` and `merchant/orders/*` endpoints + dedicated services, reusing the existing pricing/quote/creation/settlement core via a threaded optional `MerchantOrderContext`. The customer/P2P path passes `null` and is behaviourally unchanged. Settlement is free (`seller_earnings.seller_user_id = order.sender_user_id`; a merchant order's sender is the merchant's own user).

**Tech Stack:** Laravel 13 · PostgreSQL/PostGIS (clickbar/magellan) · Sanctum · Spatie Permission · Pest · bcmath.

**Spec:** `docs/superpowers/specs/2026-06-12-merchant-deliveries-design.md`

---

## Worktree Division (Claude / Codex)

Mirrors the Staff CRUD and Account Moderation milestones.

| Phase | Owner | Branch | Merges |
|---|---|---|---|
| **Phase 0 — Shared Foundation** | **Claude** | `merchant-deliveries/spec` (current; holds spec + this plan) | **to `main` FIRST** |
| **Slice A — Onboarding (admin)** | **Codex** | `merchant-deliveries/onboarding` (fork from `main` after Phase 0) | after Phase 0, second |
| **Slice B — Order flow (merchant)** | **Claude** | `merchant-deliveries/orders` (fork from `main` after Phase 0) | after Phase 0, parallel to A |
| **Close-out** | **Claude** | on `main` after A + B merge | last |

**Why Phase 0 lands first:** it creates the shared primitives (`MerchantErrorCode`, `MerchantException`, lang file, `MerchantProfileFactory`) **and** makes the high-risk financial edits to `PricingService`/`QuoteService`/`CreationService`. Doing it once, solo, avoids two agents editing the financial core in parallel (merge hell + Critical Rule 1 risk). After it merges, Slice A and Slice B touch **disjoint files** (`admin/merchants/*` vs `merchant/orders/*` + the merchant order service) so they parallelize cleanly.

**Shared-DB caution (from prior milestones):** all worktrees share one Postgres. Use per-worktree test DBs via exported `DB_DATABASE` (e.g. `delivary_app_testing_claude`, `delivary_app_testing_codex`); `createdb` each once. Don't run `RefreshDatabase` suites in both worktrees simultaneously against the same DB. Docker `delivery-postgis` (127.0.0.1:5432) must be up; "connection refused" = DB down, not code.

---

## Interface Contract (both agents code against these exact names)

These are defined in **Phase 0** and consumed by both slices. Do not rename.

```php
// app/ValueObjects/MerchantOrderContext.php
final readonly class MerchantOrderContext {
    public int $merchantProfileId;
    public ?string $commissionRateOverride;   // decimal string or null
    public ?string $driverFeeCutOverride;      // decimal string or null
    public string $businessName;               // → order.sender_name
    public ?string $contactPhone;              // → order.sender_phone
    public static function fromProfile(\App\Models\MerchantProfile $p): self;
}

// app/Enums/MerchantErrorCode.php — cases + httpStatus()
UserNotFound(404) AlreadyMerchant(422) AccountNotEligible(422)
InvalidStatusTransition(422) MerchantNotActive(403) MissingPickup(422)

// app/Exceptions/Merchant/MerchantException.php
// carries MerchantErrorCode $errorCode; ->errorCode(); rendered in bootstrap/app.php

// Middleware alias (Slice B): 'active.merchant' => EnsureActiveMerchant::class
// Service entry (Slice B): MerchantOrderCreationService::create(User $merchantUser, array $input, ?string $idempotencyKey = null): Order

// CreationService new signature (Phase 0):
//   create(User $sender, array $input, ?string $idempotencyKey = null, ?MerchantOrderContext $merchant = null): Order
// QuoteService new signature (Phase 0):
//   quote(OrderType, float x4, ItemSize, string itemPrice, string deliveryFeePayer, ?MerchantOrderContext $merchant = null): array
// PricingService new signature (Phase 0):
//   compute(OrderType, float x4, ItemSize, string itemPrice, string deliveryFeePayer, string paymentMethod, ?MerchantOrderContext $merchant = null): array
```

---

## File Structure

**Phase 0 (Claude) — created:**
- `app/Enums/MerchantErrorCode.php`
- `app/Exceptions/Merchant/MerchantException.php`
- `app/ValueObjects/MerchantOrderContext.php`
- `database/factories/MerchantProfileFactory.php`
- `lang/en/merchant_messages.php`, `lang/ar/merchant_messages.php`

**Phase 0 (Claude) — modified:**
- `composer.json` (php ^8.4) + `composer.lock`
- `docs/CLAUDE.md`, `docs/SYSTEM_SPECIFICATION.md` (PHP 8.4)
- `app/Services/Order/PricingService.php`
- `app/Services/Order/QuoteService.php`
- `app/Services/Order/CreationService.php`
- `bootstrap/app.php` (render `MerchantException`)

**Slice A (Codex) — created:**
- `app/Policies/MerchantProfilePolicy.php`
- `app/Http/Requests/Admin/Merchant/{StoreMerchantRequest,UpdateMerchantRequest}.php`
- `app/Http/Resources/MerchantResource.php`
- `app/Services/Merchant/MerchantOnboardingService.php`
- `app/Http/Controllers/Admin/MerchantController.php` (`lookup` is a method on it, reusing the anti-enumeration logic of `AdminUserLookupController`)
- `tests/Feature/Admin/MerchantOnboardingTest.php`, `tests/Unit/Merchant/MerchantOnboardingServiceTest.php`

**Slice A (Codex) — modified:**
- `routes/api.php` (admin/merchants group)

**Slice B (Claude) — created:**
- `app/Http/Middleware/EnsureActiveMerchant.php`
- `app/Services/Merchant/MerchantOrderCreationService.php`
- `app/Http/Requests/Merchant/{QuoteMerchantOrderRequest,StoreMerchantOrderRequest}.php`
- `app/Http/Controllers/Merchant/MerchantOrderController.php`
- `tests/Feature/Merchant/MerchantOrderTest.php`, `tests/Feature/Smoke/MerchantDeliveryTest.php`

**Slice B (Claude) — modified:**
- `routes/api.php` (merchant/orders group), `bootstrap/app.php` (middleware alias)

> **`routes/api.php` is touched by both slices** — different groups, but reconcile at merge. Slice A appends an `admin/merchants` group; Slice B appends a `merchant/orders` group. Trivial conflict if any.

---

# PHASE 0 — Shared Foundation (Claude, merges first)

### Task 0.1: Housekeeping — PHP 8.4 floor

**Files:**
- Modify: `composer.json`, `composer.lock`, `docs/CLAUDE.md`, `docs/SYSTEM_SPECIFICATION.md`

- [ ] **Step 1: Bump the constraint**

In `composer.json` change `"php": "^8.3"` → `"php": "^8.4"`.

- [ ] **Step 2: Validate + refresh lock platform metadata**

Run: `composer validate && composer update --lock`
Expected: "valid"; lock's `platform`/content-hash refreshed, no dependency version changes.

- [ ] **Step 3: Docs**

In `docs/CLAUDE.md` Packages/TL;DR add the PHP 8.4 requirement. In `docs/SYSTEM_SPECIFICATION.md` §17.16 change any "PHP 8.3" → "PHP 8.4".

- [ ] **Step 4: Commit**

```bash
git add composer.json composer.lock docs/CLAUDE.md docs/SYSTEM_SPECIFICATION.md
git commit -m "chore: declare PHP 8.4 floor (locked deps require >=8.4) + docs"
```

---

### Task 0.2: `MerchantErrorCode` + `MerchantException` + render + lang

**Files:**
- Create: `app/Enums/MerchantErrorCode.php`, `app/Exceptions/Merchant/MerchantException.php`, `lang/en/merchant_messages.php`, `lang/ar/merchant_messages.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Unit/Merchant/MerchantErrorCodeTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Unit/Merchant/MerchantErrorCodeTest.php
use App\Enums\MerchantErrorCode;

it('maps every merchant error code to an http status', function () {
    expect(MerchantErrorCode::UserNotFound->httpStatus())->toBe(404)
        ->and(MerchantErrorCode::AlreadyMerchant->httpStatus())->toBe(422)
        ->and(MerchantErrorCode::AccountNotEligible->httpStatus())->toBe(422)
        ->and(MerchantErrorCode::InvalidStatusTransition->httpStatus())->toBe(422)
        ->and(MerchantErrorCode::MerchantNotActive->httpStatus())->toBe(403)
        ->and(MerchantErrorCode::MissingPickup->httpStatus())->toBe(422);
});
```

- [ ] **Step 2: Run — expect FAIL** (`vendor/bin/pest tests/Unit/Merchant/MerchantErrorCodeTest.php`) — "Class MerchantErrorCode not found".

- [ ] **Step 3: Implement the enum** (mirror `app/Enums/ModerationErrorCode.php`)

```php
<?php
declare(strict_types=1);
namespace App\Enums;

enum MerchantErrorCode: string
{
    case UserNotFound = 'user_not_found';
    case AlreadyMerchant = 'already_merchant';
    case AccountNotEligible = 'account_not_eligible';
    case InvalidStatusTransition = 'invalid_status_transition';
    case MerchantNotActive = 'merchant_not_active';
    case MissingPickup = 'missing_pickup';

    public function httpStatus(): int
    {
        return match ($this) {
            self::UserNotFound => 404,
            self::MerchantNotActive => 403,
            self::AlreadyMerchant,
            self::AccountNotEligible,
            self::InvalidStatusTransition,
            self::MissingPickup => 422,
        };
    }
}
```

- [ ] **Step 4: Implement the exception** (mirror `app/Exceptions/Order/OrderDomainException.php` — note the renamed `$errorCode` to avoid shadowing `RuntimeException::$code`, per the settlement-milestone bug)

```php
<?php
declare(strict_types=1);
namespace App\Exceptions\Merchant;

use App\Enums\MerchantErrorCode;
use RuntimeException;

final class MerchantException extends RuntimeException
{
    public function __construct(
        public readonly MerchantErrorCode $errorCode,
        string $message = '',
    ) {
        parent::__construct($message);
    }

    public function errorCode(): MerchantErrorCode
    {
        return $this->errorCode;
    }
}
```

- [ ] **Step 5: Lang files** (all keys both slices use)

```php
<?php // lang/en/merchant_messages.php
return [
    'user_not_found' => 'No user found for that identifier.',
    'already_merchant' => 'This user already has a merchant profile.',
    'account_not_eligible' => 'This account is not eligible to become a merchant.',
    'invalid_status_transition' => 'That merchant status change is not allowed.',
    'merchant_not_active' => 'Your merchant account is not active.',
    'missing_pickup' => 'A pickup address and location are required.',
];
```
Create `lang/ar/merchant_messages.php` with the same keys (Arabic strings; copy English as placeholder if unsure — the project scaffolds ar then wires later).

- [ ] **Step 6: Render in `bootstrap/app.php`** — find the `->withExceptions(function (Exceptions $exceptions)` block where `StaffException`/`ModerationException` are rendered and add:

```php
$exceptions->render(function (\App\Exceptions\Merchant\MerchantException $e) {
    return response()->json([
        'message' => $e->getMessage(),
        'error_code' => $e->errorCode()->value,
    ], $e->errorCode()->httpStatus());
});
```

- [ ] **Step 7: Run — expect PASS.** Commit.

```bash
git add app/Enums/MerchantErrorCode.php app/Exceptions/Merchant/ lang/en/merchant_messages.php lang/ar/merchant_messages.php bootstrap/app.php tests/Unit/Merchant/MerchantErrorCodeTest.php
git commit -m "feat(merchant): MerchantErrorCode + MerchantException + render + lang"
```

---

### Task 0.3: `MerchantProfileFactory`

**Files:**
- Create: `database/factories/MerchantProfileFactory.php`
- Test: `tests/Unit/Merchant/MerchantProfileFactoryTest.php`

- [ ] **Step 1: Failing test**

```php
<?php // tests/Unit/Merchant/MerchantProfileFactoryTest.php
use App\Models\MerchantProfile;
use App\Enums\MerchantStatus;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('builds an active merchant profile bound to a user', function () {
    $m = MerchantProfile::factory()->create();
    expect($m->user)->not->toBeNull()
        ->and($m->status)->toBeInstanceOf(MerchantStatus::class)
        ->and($m->public_id)->not->toBeEmpty();
});

it('can build a suspended merchant via state', function () {
    expect(MerchantProfile::factory()->suspended()->create()->status)
        ->toBe(MerchantStatus::Suspended);
});
```

- [ ] **Step 2: Run — expect FAIL** ("Call to undefined method ... factory()").

- [ ] **Step 3: Implement** — link the model: add `use HasFactory;` to `app/Models/MerchantProfile.php` if absent. Then:

```php
<?php
declare(strict_types=1);
namespace Database\Factories;

use App\Enums\MerchantStatus;
use App\Models\MerchantProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MerchantProfile> */
final class MerchantProfileFactory extends Factory
{
    protected $model = MerchantProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'business_name' => fake()->company(),
            'business_phone' => null,
            'status' => MerchantStatus::Active->value,
            'approved_at' => now(),
            'commission_rate_override' => null,
            'driver_fee_cut_override' => null,
            'default_pickup_address' => null,
            'default_pickup_location' => null,
            'notes' => null,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => MerchantStatus::Suspended->value]);
    }

    public function banned(): static
    {
        return $this->state(fn () => ['status' => MerchantStatus::Banned->value]);
    }
}
```

- [ ] **Step 4: Run — expect PASS.** Commit.

```bash
git add database/factories/MerchantProfileFactory.php app/Models/MerchantProfile.php tests/Unit/Merchant/MerchantProfileFactoryTest.php
git commit -m "feat(merchant): MerchantProfileFactory"
```

---

### Task 0.4: `MerchantOrderContext` value object

**Files:**
- Create: `app/ValueObjects/MerchantOrderContext.php`
- Test: `tests/Unit/Merchant/MerchantOrderContextTest.php`

- [ ] **Step 1: Failing test**

```php
<?php // tests/Unit/Merchant/MerchantOrderContextTest.php
use App\Models\MerchantProfile;
use App\ValueObjects\MerchantOrderContext;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('derives context fields from a merchant profile', function () {
    $m = MerchantProfile::factory()->create([
        'business_name' => 'Acme', 'business_phone' => '0910000000',
        'commission_rate_override' => '0.0500', 'driver_fee_cut_override' => '0.0300',
    ]);
    $ctx = MerchantOrderContext::fromProfile($m);
    expect($ctx->merchantProfileId)->toBe($m->id)
        ->and($ctx->businessName)->toBe('Acme')
        ->and($ctx->contactPhone)->toBe('0910000000')
        ->and($ctx->commissionRateOverride)->toBe('0.0500')
        ->and($ctx->driverFeeCutOverride)->toBe('0.0300');
});

it('falls back to the owner phone when business phone is null', function () {
    $m = MerchantProfile::factory()->create(['business_phone' => null]);
    expect(MerchantOrderContext::fromProfile($m)->contactPhone)
        ->toBe($m->user->phone_number);
});
```

- [ ] **Step 2: Run — expect FAIL.**

- [ ] **Step 3: Implement**

```php
<?php
declare(strict_types=1);
namespace App\ValueObjects;

use App\Models\MerchantProfile;

final readonly class MerchantOrderContext
{
    public function __construct(
        public int $merchantProfileId,
        public ?string $commissionRateOverride,
        public ?string $driverFeeCutOverride,
        public string $businessName,
        public ?string $contactPhone,
    ) {}

    public static function fromProfile(MerchantProfile $profile): self
    {
        return new self(
            merchantProfileId: $profile->id,
            commissionRateOverride: $profile->commission_rate_override === null
                ? null : (string) $profile->commission_rate_override,
            driverFeeCutOverride: $profile->driver_fee_cut_override === null
                ? null : (string) $profile->driver_fee_cut_override,
            businessName: (string) $profile->business_name,
            contactPhone: $profile->contactPhone(),
        );
    }
}
```

- [ ] **Step 4: Run — expect PASS.** Commit.

```bash
git add app/ValueObjects/MerchantOrderContext.php tests/Unit/Merchant/MerchantOrderContextTest.php
git commit -m "feat(merchant): MerchantOrderContext value object"
```

---

### Task 0.5: `PricingService` — sale-order commission + override

**Files:**
- Modify: `app/Services/Order/PricingService.php:35-100`
- Test: `tests/Unit/Order/PricingServiceMerchantTest.php`

- [ ] **Step 1: Failing test**

```php
<?php // tests/Unit/Order/PricingServiceMerchantTest.php
use App\Enums\{ItemSize, OrderType};
use App\Services\Order\PricingService;
use App\ValueObjects\MerchantOrderContext;
use Tests\Support\TestWorld;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->world = TestWorld::create();              // seeds region/settings + pickup/dropoff
    \App\Models\PlatformSetting::set('pricing.item_commission_rate', '0.1000');
});

it('snapshots merchant commission on a merchant_delivery order (sale-order predicate)', function () {
    $p = app(PricingService::class)->compute(
        OrderType::MerchantDelivery,
        $this->world->pickup['lat'], $this->world->pickup['lng'],
        $this->world->dropoff['lat'], $this->world->dropoff['lng'],
        ItemSize::Small, '100.00', 'receiver', 'cash',
    );
    // platform rate 0.10 now applies to merchant_delivery (was 0 before)
    expect($p['commission_rate'])->toBe('0.1000')
        ->and($p['commission_amount'])->toBe('10.00');
});

it('applies the merchant override rate when context is given', function () {
    $ctx = new MerchantOrderContext(1, '0.0500', '0.0300', 'Acme', null);
    $p = app(PricingService::class)->compute(
        OrderType::MerchantDelivery,
        $this->world->pickup['lat'], $this->world->pickup['lng'],
        $this->world->dropoff['lat'], $this->world->dropoff['lng'],
        ItemSize::Small, '100.00', 'receiver', 'cash', $ctx,
    );
    expect($p['commission_rate'])->toBe('0.0500')
        ->and($p['commission_amount'])->toBe('5.00')
        ->and($p['driver_fee_cut_rate'])->toBe('0.0300');
});
```

> If `PlatformSetting::set(...)` / `TestWorld` field names differ, mirror `tests/Feature/Smoke/OrdersSettlementTest.php` setup. Confirm the helper API before writing.

- [ ] **Step 2: Run — expect FAIL** (merchant commission is `0`).

- [ ] **Step 3: Implement** — change the signature and the two rate blocks.

Signature (append param):
```php
public function compute(
    OrderType $orderType,
    float $pickupLat, float $pickupLng, float $receiverLat, float $receiverLng,
    ItemSize $itemSize,
    string $itemPrice,
    string $deliveryFeePayer,
    string $paymentMethod,
    ?\App\ValueObjects\MerchantOrderContext $merchant = null,   // NEW
): array {
```

Replace the commission block (was lines 71-75):
```php
// Commission applies to SALE orders (p2p_sale OR merchant_delivery).
$isSale = $orderType === OrderType::P2pSale || $orderType === OrderType::MerchantDelivery;
$commissionRate = $isSale
    ? ($merchant?->commissionRateOverride
        ?? (string) PlatformSetting::get('pricing.item_commission_rate', 0))
    : '0';
$commissionAmount = bcmul($itemPrice, $commissionRate, 2);
```

Replace the driver-cut rate line (was line 77):
```php
$driverCutRate = $merchant?->driverFeeCutOverride
    ?? (string) PlatformSetting::get('pricing.driver_fee_cut_rate', 0.02);
```

- [ ] **Step 4: Run new test — expect PASS. Run the full order/pricing suite to confirm no regression on the customer path** (`vendor/bin/pest tests/Feature/Smoke/OrdersSettlementTest.php tests/Unit/Order`). Expected: all green (P2P/standard unchanged because `$merchant` is null and P2P commission predicate still fires).

- [ ] **Step 5: Commit.**

```bash
git add app/Services/Order/PricingService.php tests/Unit/Order/PricingServiceMerchantTest.php
git commit -m "feat(pricing): sale-order commission predicate + merchant rate override"
```

---

### Task 0.6: `QuoteService` — context + rates in token

**Files:**
- Modify: `app/Services/Order/QuoteService.php`
- Test: `tests/Unit/Order/QuoteServiceMerchantTest.php`

- [ ] **Step 1: Failing test** — assert the signed token payload carries the rates + merchant id.

```php
<?php // tests/Unit/Order/QuoteServiceMerchantTest.php
use App\Enums\{ItemSize, OrderType};
use App\Services\Order\QuoteService;
use App\Support\QuoteToken;
use App\ValueObjects\MerchantOrderContext;
use Tests\Support\TestWorld;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => $this->world = TestWorld::create());

it('embeds commission_rate, driver_fee_cut_rate and merchant_profile_id in the token', function () {
    $ctx = new MerchantOrderContext(7, '0.0500', '0.0300', 'Acme', null);
    $res = app(QuoteService::class)->quote(
        OrderType::MerchantDelivery,
        $this->world->pickup['lat'], $this->world->pickup['lng'],
        $this->world->dropoff['lat'], $this->world->dropoff['lng'],
        ItemSize::Small, '100.00', 'receiver', $ctx,
    );
    $payload = QuoteToken::verify($res['quote_token']);   // mirror however CreationService reads it
    expect($payload['commission_rate'])->toBe('0.0500')
        ->and($payload['driver_fee_cut_rate'])->toBe('0.0300')
        ->and($payload['merchant_profile_id'])->toBe(7);
});
```

> Confirm the exact `QuoteToken` verify API used by `CreationService::verifiedQuotePayload()` and match it; adjust the assertion accordingly.

- [ ] **Step 2: Run — expect FAIL** (keys absent).

- [ ] **Step 3: Implement** — append `?MerchantOrderContext $merchant = null` to `quote(...)`, forward it to `compute(..., $merchant)`, and add three keys to `$payload`:

```php
$pricing = $this->pricing->compute(
    $orderType, $pickupLat, $pickupLng, $receiverLat, $receiverLng,
    $itemSize, $itemPrice, $deliveryFeePayer, $paymentMethod, $merchant,   // NEW
);
// ...
$payload = [
    // ... existing keys ...
    'commission_amount' => $pricing['commission_amount'],
    'driver_fee_cut_amount' => $pricing['driver_fee_cut_amount'],
    'commission_rate' => $pricing['commission_rate'],            // NEW
    'driver_fee_cut_rate' => $pricing['driver_fee_cut_rate'],    // NEW
    'merchant_profile_id' => $merchant?->merchantProfileId,      // NEW (null for customer path)
    'expires_at' => $expiresAt,
];
```

- [ ] **Step 4: Run — expect PASS. Run existing quote/order tests — expect green** (customer path adds `merchant_profile_id => null`, harmless).

- [ ] **Step 5: Commit.**

```bash
git add app/Services/Order/QuoteService.php tests/Unit/Order/QuoteServiceMerchantTest.php
git commit -m "feat(quote): thread merchant context; embed rates + merchant_profile_id in token"
```

---

### Task 0.7: `CreationService` — accept `?MerchantOrderContext`

**Files:**
- Modify: `app/Services/Order/CreationService.php` (`create`, item_price/payer block ~60-68, `Order::create` block ~110-125, `assertQuoteMatchesRequest` ~216-237, `assertQuotePriceStillCurrent` ~246-274)
- Test: `tests/Unit/Order/CreationServiceMerchantContextTest.php`

This is the highest-risk task — the customer/P2P path must stay byte-for-byte equivalent when `$merchant === null`.

- [ ] **Step 1: Failing test** (drive the four behaviours)

```php
<?php // tests/Unit/Order/CreationServiceMerchantContextTest.php
use App\Enums\{ItemSize, OrderType};
use App\Models\{MerchantProfile, User};
use App\Services\Order\{CreationService, QuoteService};
use App\ValueObjects\MerchantOrderContext;
use Tests\Support\TestWorld;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->world = TestWorld::create();
    $this->merchant = MerchantProfile::factory()->create([
        'business_name' => 'Acme Shop', 'business_phone' => '0915550000',
        'commission_rate_override' => '0.0500',
    ]);
});

function merchantQuoteInput(object $world, string $itemPrice): array {
    $ctx = MerchantOrderContext::fromProfile(test()->merchant);
    $q = app(QuoteService::class)->quote(
        OrderType::MerchantDelivery,
        $world->pickup['lat'], $world->pickup['lng'],
        $world->dropoff['lat'], $world->dropoff['lng'],
        ItemSize::Small, $itemPrice, 'receiver', $ctx,
    );
    return [/* build the create input mirroring an existing order-create test, with quote_token => $q['quote_token'], order_type => 'merchant_delivery', item_size, item_price, pickup_* , receiver_* ... */];
}

it('writes business identity + merchant_profile_id + snapshots merchant commission', function () {
    $ctx = MerchantOrderContext::fromProfile($this->merchant);
    $input = merchantQuoteInput($this->world, '100.00');
    $order = app(CreationService::class)->create($this->merchant->user, $input, null, $ctx);

    expect($order->order_type)->toBe(OrderType::MerchantDelivery)
        ->and($order->merchant_profile_id)->toBe($this->merchant->id)
        ->and($order->sender_user_id)->toBe($this->merchant->user_id)
        ->and($order->sender_name)->toBe('Acme Shop')
        ->and($order->sender_phone)->toBe('0915550000')
        ->and($order->delivery_fee_payer)->toBe('receiver')
        ->and((string) $order->commission_amount)->toBe('5.00');   // 0.05 × 100
});

it('allows item_price = 0 (pure fulfillment) on a merchant order', function () {
    $ctx = MerchantOrderContext::fromProfile($this->merchant);
    $order = app(CreationService::class)->create(
        $this->merchant->user, merchantQuoteInput($this->world, '0.00'), null, $ctx,
    );
    expect((string) $order->item_price)->toBe('0.00');
});
```

> The `merchantQuoteInput()` body must mirror the input array shape used by an existing order-create test (look at `tests/Feature/Smoke/OrdersHappyPathTest.php` or the P2P create test). Fill it in concretely before running — no placeholders in the committed test.

- [ ] **Step 2: Run — expect FAIL.**

- [ ] **Step 3: Implement** — five edits, all gated on `$merchant`:

(a) Signature:
```php
public function create(User $sender, array $input, ?string $idempotencyKey = null,
    ?\App\ValueObjects\MerchantOrderContext $merchant = null): Order
```

(b) item_price predicate (was lines 62-67) — allow item_price for sale orders, force receiver payer for merchant:
```php
$isSale = $orderType === OrderType::P2pSale || $orderType === OrderType::MerchantDelivery;
$itemPrice = $isSale
    ? bcadd((string) ($input['item_price'] ?? '0'), '0', 2)
    : '0.00';
$payer = match (true) {
    $orderType === OrderType::P2pSale => 'receiver',
    $merchant !== null => 'receiver',                  // spec §4.4 merchant: receiver, fixed
    default => (string) ($input['delivery_fee_payer'] ?? 'sender'),
};
```

(c) Guard bypass — replace the unconditional `$this->assertNotMerchantFlow($sender);` (line 85) with:
```php
if ($merchant === null) {
    $this->assertNotMerchantFlow($sender);
}
```

(d) Thread `$merchant` into the fresh pricing call (`$this->pricing->compute(...)`, ~line 71) and into `assertQuoteMatchesRequest` / `assertQuotePriceStillCurrent` (add a param to both and pass `$merchant`). In `assertQuoteMatchesRequest` add to `$matches`:
```php
&& (int) ($payload['merchant_profile_id'] ?? 0) === (int) ($merchant?->merchantProfileId ?? 0)
```
In `assertQuotePriceStillCurrent` add to `$changed` and pass the context to the regenerated quote:
```php
$changed = /* existing... */
    || bccomp((string) $fresh['commission_rate'], (string) ($payload['commission_rate'] ?? ''), 4) !== 0
    || bccomp((string) $fresh['driver_fee_cut_rate'], (string) ($payload['driver_fee_cut_rate'] ?? ''), 4) !== 0;
// ...
throw new QuoteMismatchException($this->quotes->quote(
    $orderType, /* coords */, $itemSize, $itemPrice, $payer, $merchant,   // pass context
));
```

(e) `Order::create([...])` block (~line 111) — set the merchant dimension + business identity when `$merchant !== null`:
```php
'order_type' => $orderType->value,
// ...
'merchant_profile_id' => $merchant?->merchantProfileId,
'sender_user_id' => $sender->id,
'sender_phone' => $merchant !== null ? (string) $merchant->contactPhone : (string) $sender->phone_number,
'sender_name'  => $merchant !== null ? $merchant->businessName : $sender->fullName(),
```

- [ ] **Step 4: Run merchant-context test — PASS. Run the WHOLE order suite** (`vendor/bin/pest tests/Feature tests/Unit/Order`) — **all green**; the customer path is unchanged because every new branch is `$merchant`-gated and the P2P predicate still fires.

- [ ] **Step 5: Commit.**

```bash
git add app/Services/Order/CreationService.php tests/Unit/Order/CreationServiceMerchantContextTest.php
git commit -m "feat(order): CreationService accepts optional MerchantOrderContext"
```

---

### Task 0.8: Phase 0 verification + merge to main

- [ ] **Step 1:** `vendor/bin/pint` (whole project must stay Pint-clean — CI enforces it).
- [ ] **Step 2:** Full suite green: `DB_DATABASE=delivary_app_testing vendor/bin/pest`. Expected: all prior tests + the new Phase-0 unit tests pass.
- [ ] **Step 3:** Open PR `merchant-deliveries/spec → main`, confirm CI green, merge. (gh not installed — use the GitHub UI link from `git push`.)
- [ ] **Step 4:** After merge, both agents `git checkout main && git pull`, then fork their slice branches.

---

# SLICE A — Onboarding (Codex, branch `merchant-deliveries/onboarding`)

> Fork from `main` after Phase 0 merges. Depends only on `MerchantErrorCode`, `MerchantException`, lang, `MerchantProfileFactory` (all in main). Mirrors the **Account Moderation** + **Staff CRUD** admin patterns: `AccountModerationService` (lock+reload+guard in txn), `admin/users` routes, `ModerationPolicy`, `UserModerationController`.

### Task A.1: `MerchantProfilePolicy`

**Files:** Create `app/Policies/MerchantProfilePolicy.php`; Test `tests/Feature/Admin/MerchantPolicyTest.php`.

Admin-only on every ability. Named for the `MerchantProfile` model so Laravel auto-discovers it (do **not** register in `AppServiceProvider`).

- [ ] **Step 1: Failing test** — a non-admin gets 403 on `GET /api/admin/merchants` (write after routes exist; for now unit-test the policy returns false for non-admin, true for admin).

```php
<?php // tests/Feature/Admin/MerchantPolicyTest.php
use App\Models\{MerchantProfile, User};
use App\Policies\MerchantProfilePolicy;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('allows admins and denies everyone else', function () {
    $admin = User::factory()->create(); $admin->assignRole('admin');
    $plain = User::factory()->create();
    $p = new MerchantProfilePolicy;
    expect($p->viewAny($admin))->toBeTrue()
        ->and($p->viewAny($plain))->toBeFalse()
        ->and($p->create($admin))->toBeTrue()
        ->and($p->update($plain, MerchantProfile::factory()->make()))->toBeFalse();
});
```
> Ensure roles are seeded in the test (`TestWorld::create()` seeds roles, or call the role seeder).

- [ ] **Step 2-4:** Run FAIL → implement (each method `return $user->hasRole('admin');` for `viewAny/view/create/update/suspend/reactivate/ban`) → run PASS.
- [ ] **Step 5:** Commit `feat(merchant): MerchantProfilePolicy (admin-only, auto-discovered)`.

### Task A.2: Request classes

**Files:** Create `app/Http/Requests/Admin/Merchant/StoreMerchantRequest.php`, `UpdateMerchantRequest.php`; Test `tests/Feature/Admin/MerchantRequestValidationTest.php`.

`StoreMerchantRequest` rules:
```php
'user_public_id' => ['required', 'string'],
'business_name' => ['required', 'string', 'max:255'],
'business_phone' => ['nullable', 'string', 'max:32'],
'commission_rate_override' => ['nullable', 'decimal:0,4', 'min:0', 'max:1'],
'driver_fee_cut_override' => ['nullable', 'decimal:0,4', 'min:0', 'max:1'],
'default_pickup_address' => ['nullable', 'string', 'max:1000'],
'default_pickup_location' => ['nullable', 'array'],
'default_pickup_location.lat' => ['required_with:default_pickup_location', 'numeric', 'between:-90,90'],
'default_pickup_location.lng' => ['required_with:default_pickup_location', 'numeric', 'between:-180,180'],
'notes' => ['nullable', 'string'],
```
`UpdateMerchantRequest` = same minus `user_public_id`, all `sometimes`. `authorize()` returns `true` (policy gates at controller/route).

- [ ] TDD: a test asserting `commission_rate_override > 1` and `< 0` both fail validation; bounds are the key behaviour. Commit `feat(merchant): admin merchant request validation (override bounds 0..1)`.

### Task A.3: `MerchantResource`

**Files:** Create `app/Http/Resources/MerchantResource.php`; covered by the feature tests in A.5.

Expose `public_id` as `id`; never the internal id. Mirror `app/Http/Resources/StaffResource.php` / `ModerationResource` shape:
```php
'id' => $this->public_id,
'business_name' => $this->business_name,
'business_phone' => $this->business_phone,
'status' => $this->status->value,
'commission_rate_override' => $this->commission_rate_override,
'driver_fee_cut_override' => $this->driver_fee_cut_override,
'default_pickup_address' => $this->default_pickup_address,
'owner' => $this->whenLoaded('user', fn () => ['id' => $this->user->public_id, 'name' => $this->user->fullName()]),
'approved_at' => $this->approved_at?->toIso8601String(),
```
- [ ] Commit `feat(merchant): MerchantResource`.

### Task A.4: `MerchantOnboardingService` — sole writer

**Files:** Create `app/Services/Merchant/MerchantOnboardingService.php`; Test `tests/Unit/Merchant/MerchantOnboardingServiceTest.php`.

Mirror `app/Services/Account/AccountModerationService.php` (lock+reload+guard in one txn; Critical Rule 3). Public methods: `create`, `update`, `suspend`, `reactivate`, `ban`. Inject nothing it doesn't need; resolve the user via `User::where('public_id', ...)`.

**`create(User $admin, array $data): MerchantProfile`** — strict order (spec §5.2):
```php
return DB::transaction(function () use ($admin, $data) {
    $user = User::query()->where('public_id', $data['user_public_id'])->first();
    if ($user === null) {
        throw new MerchantException(MerchantErrorCode::UserNotFound, trans('merchant_messages.user_not_found'));
    }
    // 2. account eligibility BEFORE any profile inspect/restore
    if ($user->account_status === AccountStatus::Banned) {
        throw new MerchantException(MerchantErrorCode::AccountNotEligible, trans('merchant_messages.account_not_eligible'));
    }
    // 3. inspect existing profile across live + soft-deleted, locked
    $existing = MerchantProfile::withTrashed()->where('user_id', $user->id)->lockForUpdate()->first();
    if ($existing !== null) {
        $isLive = $existing->deleted_at === null;
        if ($isLive || $existing->status === MerchantStatus::Banned) {
            throw new MerchantException(MerchantErrorCode::AlreadyMerchant, trans('merchant_messages.already_merchant'));
        }
        $existing->restore(); // soft-deleted, non-banned → restore + reactivate
        $profile = $existing;
    } else {
        $profile = new MerchantProfile(['user_id' => $user->id]);
    }
    $profile->fill([/* business_name, business_phone, overrides, default pickup, notes from $data */]);
    $profile->status = MerchantStatus::Active->value;
    $profile->approved_at = now();
    $profile->approved_by_admin_id = $admin->id;
    $profile->created_by_admin_id ??= $admin->id;
    $profile->save();
    $user->assignRole('merchant');
    return $profile->load('user');
});
```
> `default_pickup_location` from `['lat','lng']` → **`Point::makeGeodetic($lat, $lng)`** — argument order is **(lat, lng)** in this codebase (`CreationService.php:121`, `TestWorld.php:44`, all test helpers: `makeGeodetic(32.8872 /*lat*/, 13.1913 /*lng*/)`). Do **not** pass `($lng, $lat)` — it silently swaps coordinates. Confirm `AccountStatus` enum + `User::account_status` cast name.

**Lifecycle** — each opens a txn, `lockForUpdate()` + reload the `{merchant}`, guard the transition, write:
```php
suspend:    Active → Suspended         (else InvalidStatusTransition)
reactivate: Suspended → Active         (else InvalidStatusTransition)
ban:        Active|Suspended → Banned  (else InvalidStatusTransition); $profile->user->removeRole('merchant');
```
Role stays through suspend/reactivate; removed on ban.

- [ ] **TDD steps:** unit tests for — create-fresh assigns role + active; create when live profile → `AlreadyMerchant`; create when soft-deleted non-banned → restored; create when soft-deleted banned → `AlreadyMerchant`; create for banned account → `AccountNotEligible` (even with a trashed profile present — ordering proof); suspend/reactivate happy + invalid transition; ban removes role. Run each FAIL→PASS. Commit per logical group.

### Task A.5: `MerchantController` (admin) + lookup

**Files:** Create `app/Http/Controllers/Admin/MerchantController.php`; Test `tests/Feature/Admin/MerchantOnboardingTest.php`.

Thin controller: validate (FormRequest) → call `MerchantOnboardingService` → return `MerchantResource`. `index` paginates with optional `?status=` filter. `lookup` is a method on this same controller (`?phone=`), reusing the anti-enumeration logic of `AdminUserLookupController`. `authorize` via `MerchantProfilePolicy` (`$this->authorize('viewAny', MerchantProfile::class)` etc.).

- [ ] **TDD:** full HTTP feature tests — admin creates a merchant (201 + role assigned + active); non-admin 403; create duplicate → 422 `already_merchant`; suspend → reactivate → ban happy paths; ban then suspend → 422 `invalid_status_transition`; lookup by phone returns the user / anti-enumerates a miss. Commit.

### Task A.6: Routes

**Files:** Modify `routes/api.php` — append, mirroring the `admin/users` moderation group middleware exactly:
```php
Route::middleware(['auth:sanctum', 'role:admin', 'staff.password_change_required'])
    ->prefix('admin/merchants')->group(function (): void {
        Route::get('lookup', [MerchantController::class, 'lookup']);        // ?phone=
        Route::get('/', [MerchantController::class, 'index']);
        Route::post('/', [MerchantController::class, 'store']);
        Route::get('{merchant:public_id}', [MerchantController::class, 'show']);
        Route::patch('{merchant:public_id}', [MerchantController::class, 'update']);
        Route::post('{merchant:public_id}/suspend', [MerchantController::class, 'suspend']);
        Route::post('{merchant:public_id}/reactivate', [MerchantController::class, 'reactivate']);
        Route::post('{merchant:public_id}/ban', [MerchantController::class, 'ban']);
    });
```
> Register `{merchant}` route-model-binding to `MerchantProfile` (it already uses `getRouteKeyName() => 'public_id'`). Place `lookup` before `{merchant:public_id}` so it isn't captured as an id.

- [ ] **Step:** run the A.5 feature suite green. Commit. Then Slice A verification: Pint clean, `DB_DATABASE=delivary_app_testing_codex vendor/bin/pest`. Open PR → main.

---

# SLICE B — Order flow (Claude, branch `merchant-deliveries/orders`)

> Fork from `main` after Phase 0. Uses the threaded context from Phase 0. Touches `merchant/orders/*` + the merchant order service + middleware — disjoint from Slice A.

### Task B.1: `EnsureActiveMerchant` middleware

**Files:** Create `app/Http/Middleware/EnsureActiveMerchant.php`; Modify `bootstrap/app.php` (alias `'active.merchant'`); Test `tests/Feature/Merchant/ActiveMerchantMiddlewareTest.php`.

```php
public function handle(Request $request, Closure $next): Response
{
    $user = $request->user();
    $active = $user?->hasRole('merchant')
        && $user->merchantProfile?->status === MerchantStatus::Active;
    if (! $active) {
        throw new MerchantException(MerchantErrorCode::MerchantNotActive, trans('merchant_messages.merchant_not_active'));
    }
    return $next($request);
}
```
Alias in `bootstrap/app.php` `->withMiddleware(fn ($m) => $m->alias([... 'active.merchant' => EnsureActiveMerchant::class]))`.

- [ ] **TDD:** active merchant passes; suspended/banned/non-merchant → 403 `merchant_not_active`. Commit.

### Task B.2: `MerchantOrderCreationService`

**Files:** Create `app/Services/Merchant/MerchantOrderCreationService.php`; Test `tests/Unit/Merchant/MerchantOrderCreationServiceTest.php`.

Entry point. Resolves pickup (all-or-nothing), builds the create input, delegates to `CreationService::create(..., MerchantOrderContext::fromProfile($profile))`.

> **Pickup resolution is SHARED between quote and create** (Codex high finding): the quote endpoint also needs resolved pickup coordinates to price (the merchant may omit pickup and rely on the default). Put `resolvePickup()` as a **public** method on this service so `MerchantOrderController::quote` calls it *before* `QuoteService::quote(...)`. The quote then signs the *resolved* (possibly default) coordinates, and a merchant with no pickup + no default gets `MissingPickup` at quote time, not a confusing pricing error.

```php
public function __construct(private readonly CreationService $creation) {}

public function create(User $merchantUser, array $input, ?string $idempotencyKey = null): Order
{
    $profile = $this->requireActiveProfile($merchantUser);
    $input = $this->resolvePickup($input, $profile);          // fills pickup_address + pickup_location or throws MissingPickup
    $input['order_type'] = OrderType::MerchantDelivery->value;
    return $this->creation->create($merchantUser, $input, $idempotencyKey, MerchantOrderContext::fromProfile($profile));
}

public function requireActiveProfile(User $merchantUser): MerchantProfile
{
    $profile = $merchantUser->merchantProfile;
    if ($profile === null || $profile->status !== MerchantStatus::Active) {
        throw new MerchantException(MerchantErrorCode::MerchantNotActive, trans('merchant_messages.merchant_not_active'));
    }
    return $profile;
}
```

**`public function resolvePickup(array $input, MerchantProfile $profile): array`** — all-or-nothing, and it must **reject a partial per-order pickup** rather than silently fall back to the default (Codex medium finding; the FormRequest also guards this, but the service is unit-tested with partial input and must not default):
```php
$addr = $input['pickup_address'] ?? null;
$lat  = $input['pickup_location']['lat'] ?? null;
$lng  = $input['pickup_location']['lng'] ?? null;
$anyPresent  = $addr !== null || $lat !== null || $lng !== null;
$allPresent  = $addr !== null && $lat !== null && $lng !== null;

if ($anyPresent && ! $allPresent) {                           // partial → never default-fall-through
    throw new MerchantException(MerchantErrorCode::MissingPickup, trans('merchant_messages.missing_pickup'));
}
if ($allPresent) {
    return $input;                                            // complete per-order pickup
}
// none present → fall back to the profile default (both fields, or neither)
if ($profile->default_pickup_address !== null && $profile->default_pickup_location !== null) {
    $input['pickup_address'] = $profile->default_pickup_address;
    $input['pickup_location'] = [
        'lat' => $profile->default_pickup_location->getLatitude(),
        'lng' => $profile->default_pickup_location->getLongitude(),
    ];
    return $input;
}
throw new MerchantException(MerchantErrorCode::MissingPickup, trans('merchant_messages.missing_pickup'));
```
> Service-area validation of the resolved pickup happens automatically inside `PricingService::resolveRegion()` (called by the quote/create flow) — it throws `PickupOutOfServiceArea` if the (possibly default) pickup falls outside an active region. No extra code; assert it in a test.

- [ ] **TDD:** uses order pickup when complete; uses profile default when omitted; **partial per-order pickup → `MissingPickup`** (not silent default); none + no default → `MissingPickup`; default in a deactivated region → `PickupOutOfServiceArea`. Commit.

### Task B.3: Merchant order requests (quote + create)

**Files:** Create `app/Http/Requests/Merchant/QuoteMerchantOrderRequest.php`, `StoreMerchantOrderRequest.php`.

Mirror the existing order quote/create FormRequests, but: `order_type` is **not** a client field (forced to `merchant_delivery`); **no** `delivery_fee_payer` field (forced receiver); `item_price` is `['nullable', 'numeric', 'min:0', 'max:99999999.99']` (the `max` keeps it inside the `decimal(12,2)` `orders.item_price` column — mirror the existing order request); pickup is **all-or-nothing**:
```php
'pickup_address' => ['required_with:pickup_location', 'nullable', 'string', 'max:500'],
'pickup_location' => ['required_with:pickup_address', 'nullable', 'array'],
'pickup_location.lat' => ['required_with:pickup_location', 'numeric', 'between:-90,90'],
'pickup_location.lng' => ['required_with:pickup_location', 'numeric', 'between:-180,180'],
```
(If neither pickup field is present, the service falls back to the profile default — valid. A *partial* per-order pickup is rejected both here via `required_with` and defensively in `resolvePickup`.) Create request additionally requires `quote_token`, `receiver_*`, `item_size`, `item_description`.

**`StoreMerchantOrderRequest::authorize()` must replicate the order-create guard** (Codex high finding — `POST /api/orders` has **no** phone-verified middleware; the guard lives in `CreateOrderRequest::authorize()`):
```php
public function authorize(): bool
{
    $user = $this->user();
    return $user !== null
        && $user->phone_verified_at !== null
        && $user->canCreateOrders();
}
```
The `active.merchant` route middleware only checks merchant role/status — it does **not** cover phone verification or `canCreateOrders()`.

- [ ] Commit `feat(merchant): merchant order quote/create requests`.

### Task B.4: `MerchantOrderController`

**Files:** Create `app/Http/Controllers/Merchant/MerchantOrderController.php`; Test `tests/Feature/Merchant/MerchantOrderTest.php`.

- `quote` → `$profile = $svc->requireActiveProfile($request->user())`; **`$resolved = $svc->resolvePickup($request->validated(), $profile)`** (so a defaulted pickup is priced, or `MissingPickup` is thrown before pricing); build `MerchantOrderContext::fromProfile($profile)`; call `QuoteService::quote(...)` with the resolved pickup coords + context; return pricing+token (mirror the existing quote controller). Inject `MerchantOrderCreationService $svc` for the shared resolver.
- `store` → `MerchantOrderCreationService::create($request->user(), $validated, $idempotencyKey)` → `OrderResource`.
- `index` → `Order::forMerchant($profileId)->paginate()` → `OrderResource::collection` (scope by merchant_profile_id, NOT OrderPolicy::view).
- `show` → resolve `{order:public_id}` then assert `$order->merchant_profile_id === $request->user()->merchantProfile->id` (404/403 otherwise) → `OrderResource`.

- [ ] **TDD (the spec §8 list):** override applied + snapshotted at create; `item_price=0` → no seller_earning after delivery; override-changed-after-quote → **409** `quote_price_changed`; wrong-merchant token → **400** `invalid_quote_token`; suspended/non-merchant → 403; pickup defaulting + `MissingPickup`; standard `/api/orders` still blocks an active merchant (`merchant_use_merchant_flow`); merchant `index`/`show` exclude the same user's non-merchant orders. Commit per group.

### Task B.5: Routes

**Files:** Modify `routes/api.php` — append. The middleware matches the `POST /api/orders` group (`auth:sanctum` + `staff.password_change_required`) plus `active.merchant`. There is **no** phone-verified middleware — phone verification + `canCreateOrders()` are enforced by `StoreMerchantOrderRequest::authorize()` (Task B.3), exactly as `CreateOrderRequest` does for `/api/orders`. Apply the existing `throttle:orders_quote` / `throttle:orders_create` limiters to match the order routes:
```php
Route::middleware(['auth:sanctum', 'staff.password_change_required', 'active.merchant'])
    ->prefix('merchant/orders')->group(function (): void {
        Route::post('quote', [MerchantOrderController::class, 'quote'])->middleware('throttle:orders_quote');
        Route::post('/', [MerchantOrderController::class, 'store'])->middleware('throttle:orders_create');
        Route::get('/', [MerchantOrderController::class, 'index']);
        Route::get('{order:public_id}', [MerchantOrderController::class, 'show']);
    });
```
> Check the exact middleware on the existing `POST /api/orders` route and apply the same phone-verification guard to `store`.

- [ ] Run B.4 suite green. Commit.

### Task B.6: Smoke test

**Files:** Create `tests/Feature/Smoke/MerchantDeliveryTest.php`.

Full lifecycle mirroring `OrdersSettlementTest`: seed world → admin onboards a merchant (or `MerchantProfile::factory()` + assign role) → merchant quotes + creates a `merchant_delivery` order with `item_price=100` → driver claims/picks up/delivers (reuse the smoke helpers) → assert a `seller_earnings` row spawned for the merchant user → run settlement + clearance + payout → assert paid. Keep the Pest-smoke convention (isolated `it()`, `RefreshDatabase`, real expectations).

- [ ] Commit. Then Slice B verification: Pint clean, `DB_DATABASE=delivary_app_testing_claude vendor/bin/pest`. Open PR → main.

---

# CLOSE-OUT (Claude, on main after A + B merge)

### Task Z.1: Cross-review + merge

- [ ] Cross-review the other agent's slice (Claude reviews A, Codex reviews B), apply fixes, merge both to main. Reconcile the two `routes/api.php` group additions.

### Task Z.2: Verify + document

- [ ] **Full re-verify on merged main:** `vendor/bin/pint` clean; `DB_DATABASE=delivary_app_testing vendor/bin/pest` all green; new `MerchantDeliveryTest` green; existing `orders`/`settlement` smokes green.
- [ ] **Run `/security-review`** on the merged diff — expect no HIGH/MEDIUM (financial snapshot immutability, public_id exposure, authorization scoping).
- [ ] **Docs:** SYSTEM_SPECIFICATION §17.17 "Merchant Deliveries milestone" (endpoints table + locked decisions) + CLAUDE.md "Current Project State" (mark ✅, bump Next Steps) + Glossary if needed.
- [ ] Commit `docs(merchant): record Merchant Deliveries milestone (§17.17 + current state)`. Open docs PR → main.

---

## Self-Review notes (author)

- **Spec coverage:** §4 → Tasks 0.4-0.7; §5 onboarding → A.1-A.6; §6 order flow → B.1-B.5; §7 errors → 0.2; §8 tests → 0.5-0.7, A.4-A.5, B.2/B.4/B.6; §9 housekeeping → 0.1; §10 close-out → Z.2. All sections mapped.
- **Type consistency:** `MerchantOrderContext` fields and the `create()`/`quote()`/`compute()` signatures are fixed in the Interface Contract and reused verbatim in 0.5-0.7 and B.1-B.4.
- **Known fill-ins for the executor (not placeholders — concrete instructions):** the `merchantQuoteInput()` test helper body (mirror an existing order-create test's input array) and the exact `QuoteToken` verify API must be confirmed against the codebase before the test is committed. The phone-verified middleware name on `POST /api/orders` must be copied exactly.
