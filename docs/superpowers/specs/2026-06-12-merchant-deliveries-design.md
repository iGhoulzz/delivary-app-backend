# Merchant Deliveries (sub-project E) — Design

**Date:** 2026-06-12
**Status:** Approved — ready for implementation plan
**Spec refs:** SYSTEM_SPECIFICATION.md §3.3 (Merchant Delivery), §4.2–4.6 (commission, rate hierarchy, fee payer, item price)

---

## 1. Goal & Scope

Ship the **merchant delivery** order type end to end: an admin onboards a business as a
merchant, and that merchant creates `merchant_delivery` orders (shop → customer) that flow
through the existing order/driver/settlement pipeline.

The schema already exists (`merchant_profiles`, `OrderType::MerchantDelivery`,
`orders.merchant_profile_id`, the `MerchantDelivery` branch in settlement). The missing
piece is the **entire HTTP + service surface** plus a handful of surgical edits to shared
pricing/quote/creation code so merchant economics actually compute.

**Two slices, one milestone:**

- **Slice A — Onboarding (admin-facing):** CRUD + lifecycle for `merchant_profiles`.
- **Slice B — Order creation (merchant-facing):** active merchants quote + create
  `merchant_delivery` orders; merchants view their own orders.

**Out of scope:** merchant self-application/registration; merchant catalog/inventory;
digital pre-payment; merchant-facing settlement UI beyond the existing seller-payout
endpoints (a merchant *is* a seller and already has `/api/me/earnings` + `/api/me/seller-payouts`).

---

## 2. Locked Decisions

| # | Decision | Rationale |
|---|---|---|
| 1 | Build **both** slices this milestone (one spec). | Order flow is the blocked deliverable; onboarding is its prerequisite. |
| 2 | Onboarding is **admin invite-only, create-directly-active**. | Matches the `created_by_admin_id` / "invite-only" migration intent; the `pending` enum value stays defined but unused by this flow. |
| 3 | `MerchantStatus` is an **independent merchant lever** (separate from account moderation). | Mirrors how `DriverStatus` is independent of `AccountStatus`. An admin can pause a merchant's selling without touching the personal account. |
| 4 | Merchant orders carry an **optional `item_price`** (sale vs. pure fulfillment). | Spec §3.3 + the settlement hook (`seller_earnings` spawns only when `item_price > 0`). |
| 5 | **Receiver always pays** the delivery fee (spec §4.4) — no `delivery_fee_payer` input in the merchant flow; fixed server-side, including pure-fulfillment `item_price = 0`. | Spec-literal. |
| 6 | Merchant lifecycle audit = **minimal** (status + `approved_at/by` + `notes`); no new audit table. | Invite-only, low volume. |
| 7 | **Ban is terminal** on the merchant-profile axis (no un-ban). | Suspend is the reversible lever. Distinct from account-moderation `banned`, which stays admin-reversible. |
| 8 | Pipeline structure = **Approach A**: dedicated `merchant/*` endpoints + dedicated services that **reuse** the existing pricing/quote/creation/settlement internals via a threaded merchant-rate context. | Isolation at the entry point, reuse of the transactional core; matches the codebase's per-actor endpoint split. |

---

## 3. Architecture

```
Slice A  (admin)                     Slice B  (merchant)
  admin/merchants/*                    merchant/orders/*
  MerchantOnboardingService            MerchantOrderCreationService
    sole writer of merchant_profiles     resolves pickup + merchant context,
    lock+reload+guard in txn             builds input, delegates the write to
                                         CreationService (existing core)
                                              │
                              reuses ─────────┼───────────────────────────────
                              PricingService  (+ MerchantRateContext, sale-order commission)
                              QuoteService    (+ context, rates in payload)
                              CreationService (+ optional context, item_price for merchant)
                              CodeVerificationService / StateTransitionService — UNCHANGED
                              Settlement + seller-payouts — UNCHANGED
```

**Why settlement is free:** `seller_earnings.seller_user_id = order.sender_user_id`
(`CodeVerificationService::spawnSellerEarning`). A merchant order's sender **is** the
merchant's own user, so the existing settlement → clearance → seller-payout pipeline pays
merchants with zero changes. `merchant_profile_id` is an *additional* dimension on the
order, never a replacement for `sender_user_id`.

---

## 4. Shared-Code Changes (the only edits outside new files)

These are threaded as an **optional merchant context** so the customer/P2P path passes
`null` and is behaviorally unchanged.

### 4.1 `MerchantRateContext` value object (new, `app/ValueObjects/`)
Immutable carrier for the merchant dimension through pricing/quote/creation:
```
final readonly class MerchantRateContext {
    public int $merchantProfileId;
    public ?string $commissionRateOverride;   // null = use platform default
    public ?string $driverFeeCutOverride;     // null = use platform default
}
```
Built from a `MerchantProfile` (`MerchantRateContext::fromProfile($profile)`).

### 4.2 `PricingService::compute(...)`
- **Commission predicate change (critical):** today commission is computed only for
  `OrderType::P2pSale`. Change to **sale orders** = `P2pSale || MerchantDelivery`. Without
  this, a merchant commission override exists but merchant commission still snapshots as `0`.
- Accept an optional `?MerchantRateContext`. When present, its overrides **replace** the
  `PlatformSetting::get('pricing.item_commission_rate')` / `pricing.driver_fee_cut_rate`
  lookups — this is the §4.3 "Merchant Override" layer.
- Driver fee cut already applies to all order types; the override simply substitutes the rate.

### 4.3 `QuoteService::make(...)`
- Accept the optional `?MerchantRateContext`, forward to `compute()`.
- **Add to the signed payload + the match-check:** `commission_rate`, `driver_fee_cut_rate`,
  and `merchant_profile_id`. Today the token snapshots only the *amounts*; for
  `item_price = 0` the commission amount is `0` regardless of rate, so a rate change is
  invisible unless the rate itself is in the token.

### 4.4 `CreationService`
- `create()` accepts an optional `?MerchantRateContext $merchant = null`. When present:
  - skip `assertNotMerchantFlow` (the merchant *is* using the merchant flow);
  - set `order_type = merchant_delivery`, `merchant_profile_id`, `delivery_fee_payer = receiver`;
  - **`item_price` predicate change:** allow item_price for `P2pSale` **or**
    `MerchantDelivery` (today it's forced to `0.00` for non-P2P);
  - feed the context to pricing + quote re-verification.
- `assertQuotePriceStillCurrent()` must **pass the merchant context** when it regenerates a
  mismatch quote through `QuoteService` — otherwise the fresh quote silently uses platform
  defaults and every merchant create with an override would 422.

---

## 5. Slice A — Merchant Onboarding (admin)

### 5.1 Endpoints
All `sanctum + role:admin + MerchantPolicy`, `public_id`-bound, mirroring staff/moderation.

| Endpoint | Method | Purpose |
|---|---|---|
| `/api/admin/merchants` | GET | List/filter by status (paginated) |
| `/api/admin/merchants/lookup?phone=` | GET | Find a user to onboard (anti-enumeration) |
| `/api/admin/merchants` | POST | Create **active** merchant for an existing user |
| `/api/admin/merchants/{merchant}` | GET | Show |
| `/api/admin/merchants/{merchant}` | PATCH | Update business details / overrides / default pickup |
| `/api/admin/merchants/{merchant}/suspend` | POST | active → suspended |
| `/api/admin/merchants/{merchant}/reactivate` | POST | suspended → active |
| `/api/admin/merchants/{merchant}/ban` | POST | active\|suspended → banned (terminal) |

### 5.2 `MerchantOnboardingService` — sole writer of `merchant_profiles`
Follows the established **lock+reload+guard-in-txn** pattern (Critical Rule 3, as in
`AccountModerationService`/`StaffService`): open txn → `lockForUpdate()` the target →
guard against committed state → write.

- **Create:** the admin first hits `lookup?phone=` to resolve a user, then POSTs with
  `user_public_id` (Critical Rule 11 — accept `public_id`, never an internal id). The service
  sets `status = active`, `approved_at = now()`, `approved_by_admin_id`, `created_by_admin_id`,
  plus business fields/overrides/default pickup. Guards:
  - `UserNotFound` — target user does not exist;
  - `AlreadyMerchant` — **checked via `MerchantProfile::withTrashed()`** because `user_id`
    is `unique` and the model soft-deletes. If a **soft-deleted** profile exists for the
    user, **restore + reactivate** it (don't error, don't violate the unique index);
  - `AccountNotEligible` — target account is `banned` (don't onboard a banned account).
- **Lifecycle:** `suspend` (active→suspended), `reactivate` (suspended→active),
  `ban` (active|suspended→banned, terminal). `InvalidStatusTransition` otherwise.

### 5.3 Supporting pieces
- `MerchantPolicy` (admin-only).
- `StoreMerchantRequest`, `UpdateMerchantRequest`, thin action requests (optional `notes`/reason).
- `MerchantResource` — `public_id` as `id`; owner as nested `{id, name}`
  (public_id-safe, Critical Rule 11); `status`, overrides, default pickup, approval refs.
- `MerchantProfileFactory` (new — none exists).
- `MerchantErrorCode` enum + `MerchantException` (`httpStatus()`, rendered in `bootstrap/app.php`).

---

## 6. Slice B — Merchant Order Creation (merchant)

### 6.1 Auth gate
New `active.merchant` gate/middleware: `$user->merchantProfile?->status === MerchantStatus::Active`.
Mirrors how `role:driver` gates driver routes. Registered in `bootstrap/app.php`.

### 6.2 Endpoints (`sanctum + active.merchant`)

| Endpoint | Method | Purpose |
|---|---|---|
| `/api/merchant/orders/quote` | POST | Price a merchant delivery (forces `merchant_delivery`, applies overrides) |
| `/api/merchant/orders` | POST | Create the order (`phone-verified` like `/api/orders`) |
| `/api/merchant/orders` | GET | Merchant lists **own** orders — `Order::forMerchant($merchantProfileId)` |
| `/api/merchant/orders/{public_id}` | GET | Merchant views one own order — scoped by `merchant_profile_id` |

> **Scope by `merchant_profile_id`, not `OrderPolicy::view`** — the sender policy would also
> surface the same user's *personal* (non-merchant) orders.

### 6.3 `MerchantOrderCreationService`
Merchant entry point. Re-asserts active merchant (`MerchantNotActive`), then:
- **Pickup resolution:** use request pickup if provided; else fall back to the profile's
  `default_pickup_location` / `default_pickup_address`. If neither → `MissingPickup`.
  **The resolved pickup (including a stored default) is validated against the active service
  area at order time** (Critical Rule 16) — a default valid at onboarding can later fall in a
  deactivated area.
- Build the create input: `order_type = merchant_delivery`, optional `item_price`,
  `delivery_fee_payer = receiver` (fixed), receiver by phone (registered or guest, as P2P).
- Delegate to `CreationService::create($merchantUser, $input, $idempotencyKey, MerchantRateContext::fromProfile($profile))`.

### 6.4 Order snapshot
- `sender_user_id = merchant user id` (settlement/realtime ownership) **and**
  `merchant_profile_id` set (merchant dimension).
- **Display identity uses the business:** `sender_name = business_name`,
  `sender_phone = contactPhone()` (business phone, falling back to the owner's personal phone).

### 6.5 Settlement & realtime (unchanged, stated precisely)
- `seller_earnings` auto-spawns for `merchant_delivery` + `item_price > 0`, settling to the
  merchant's user. `item_price = 0` → no earning (pure fulfillment).
- Merchant order **status** events remain on `private:order.{order_public_id}` (sender/receiver).
- Seller-earning **clearance** remains on `private:user.{merchant_user_public_id}`.
- No order event fires on the merchant's user channel.

---

## 7. Error Handling

`MerchantErrorCode` enum → HTTP via `httpStatus()`, rendered centrally in `bootstrap/app.php`
(same pattern as `StaffErrorCode`/`ModerationErrorCode`):

| Case | HTTP | When |
|---|---|---|
| `UserNotFound` | 404 | onboarding target user missing |
| `AlreadyMerchant` | 422 | active/pending profile already exists (post-restore check) |
| `AccountNotEligible` | 422 | onboarding a `banned` account |
| `InvalidStatusTransition` | 422 | illegal merchant lifecycle move |
| `MerchantNotActive` | 403 | non-active merchant hits the order flow |
| `MissingPickup` | 422 | no per-order pickup and no profile default |

Localized strings: `lang/en/merchant_messages.php` + `lang/ar/merchant_messages.php`.

---

## 8. Testing

**Slice A** — `MerchantProfileFactory`; feature tests: create-active, lookup
(anti-enumeration), suspend/reactivate, ban-terminal, guards (already-merchant /
**soft-deleted restore** / banned-account / invalid-transition / admin-only);
`MerchantOnboardingService` unit tests for the lock+guard paths.

**Slice B** — feature tests:
- merchant quote+create with `item_price > 0` → settles to merchant (`seller_earnings` row);
- `item_price = 0` → **no** `seller_earning`;
- merchant commission override **applied and snapshotted** on the order;
- **override changed after quote → merchant quote mismatch (422)**, including the
  `item_price = 0` case (proves rates-in-token works);
- suspended / non-merchant blocked (`MerchantNotActive`);
- pickup defaulting (uses profile default; missing → `MissingPickup`; deactivated area rejected);
- standard `/api/orders` still blocks an active merchant via `assertNotMerchantFlow`;
- **merchant endpoints exclude non-merchant orders created by the same user.**

**Smoke** — `tests/Feature/Smoke/MerchantDeliveryTest.php`: onboard → merchant creates order
→ driver delivers → `seller_earning` → settlement → payout. Keeps the Pest-smoke convention.

---

## 9. Housekeeping (done first, on the milestone branch)

Deferred from the test-infrastructure milestone:
- Bump `composer.json` `"php": "^8.3"` → `"^8.4"` (locked Symfony 8 / collision 8.9 require
  ≥8.4; dev + CI already run 8.4), then `composer validate`.
- **Refresh `composer.lock` platform metadata** — its `platform`/`platform-overrides`
  section currently records PHP `^8.3`; run `composer update --lock` (or equivalent) so the
  lock's platform matches.
- Document PHP 8.4 in `docs/CLAUDE.md` (TL;DR / Packages) and fix any "PHP 8.3" mention in
  SYSTEM_SPEC §17.16.

---

## 10. Docs close-out (end of milestone)

- SYSTEM_SPECIFICATION §17.17 "Merchant Deliveries milestone" + CLAUDE.md "Current Project
  State" / "Next Steps".
- Verify on the merged branch: full Pest suite green, Pint clean, new smoke green,
  security review (no HIGH/MEDIUM).
