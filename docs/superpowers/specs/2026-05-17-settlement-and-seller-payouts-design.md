# Settlement & Seller Payouts — Design

**Status:** 🟡 Approved, awaiting implementation plan
**Date:** 2026-05-17
**Author:** Claude (brainstorm with user)
**Implements spec sections:** §4.9 (Pending → Available timing), §4.10 (Payouts), §11 (Cash Settlement)

---

## 1. Purpose

Close the financial loop that the order lifecycle milestones opened. Drivers accumulate cash, earnings, and debt during delivery work; sellers accumulate withdrawable proceeds from completed sales. This milestone gives:

- **Office staff** a way to reconcile a driver's three buckets (`cash_to_deposit`, `earnings_balance`, `debt_balance`) in a single physical visit and atomic API call.
- **Sellers** visibility into where their sales proceeds are in the pipeline and the ability to collect cash at any active office.
- **Admins** a global view + a narrow correcting-settlement capability for the inevitable miscount.

Until this ships, settled cash is stranded — drivers can't legally hold more than `max_cash_liability`, and sellers can't withdraw anything they've earned. After this ships, the platform's end-to-end cash cycle works.

---

## 2. Non-Goals

- **Cash delivery to seller's address.** Locked at office-pickup-only per spec §4.10 (and reconfirmed during brainstorm). Driver-to-seller cash delivery is deferred to a v2 milestone.
- **Bank transfers, mobile money, or any off-platform payout.** Same as above.
- **Bavix wallet as the source of truth for seller balances.** Bavix tables stay installed but untouched. Seller balances are computed from `seller_earnings` rows. A future milestone may layer Bavix on top if we need true wallet semantics (top-ups, intra-platform transfers); MVP doesn't.
- **Forced payout cadence.** Sellers leave their `available` earnings sitting indefinitely if they want — there is no auto-expiry, no idle alert.
- **Settlement-side payout codes / seller-side OTP.** Identity verification at the counter is visual + ID + phone match. Staff judgement, no app interaction required from the seller.
- **Real-time push notifications for settlement events.** Polling + standard `OrderStatusChanged`-equivalent events fire, but the Reverb/FCM layer is its own future milestone.
- **Self-service driver dispute filing.** A driver who disagrees with an agent's count walks away — no record exists per §11.4. If they want to file a formal complaint, it's a support case outside this milestone.

---

## 3. Locked Decisions

| # | Decision | Rationale |
|---|---|---|
| 1 | **Full scope:** driver settlement + seller payout + seller-earnings clearance tracking all in one milestone | The financial loop is incomplete without all three. Splitting forces re-opening the same code paths weeks later. |
| 2 | **All-or-nothing settlement** — every settlement zeros out all three buckets in one shot | Matches spec §11.2 verbatim. Driver chooses *when* to walk in; the 3-bucket model already gives flexibility. |
| 3 | **Single atomic POST** — no propose-then-confirm. `POST /api/office/settlements` is the only commit endpoint; an optional read-only `GET .../settlement-preview` exists purely as an info helper. | Matches §11.4 "no record on disagreement." No abandoned half-settlement rows. `SettlementStatus` enum already has no "pending" state. |
| 4 | **Disagreement leaves zero trace.** Agent doesn't call the API when they and the driver can't agree. | Matches §11.4 literally. A "disagreement log" table invites pressure for UI/dashboards/quasi-evidence that the spec explicitly rejects. |
| 5 | **Server computes outcome from cash numbers** (match / acknowledged shortage). **Excess is rejected with 422** — agent must hand excess cash back before submitting. | Agent's job is counting cash, not classifying outcomes. Server is source-of-truth for what each bucket *should* clear by. Excess in the DB = the platform "holds" money the driver isn't owed, which violates spec Critical Rule 2. |
| 6 | **Seller earnings live in a new `seller_earnings` table** (1:1 with sale orders), not as columns on `orders` | Avoids 6+ NULL columns on the already-wide `orders` table. Indexable on `(seller_user_id, status)` and `(status, cleared_at)`. Row exists only for orders that physically generated an earning. |
| 7 | **48h clearance window**, configurable via `payouts.clearance_hours` (default `48`) | Standard belt-and-suspenders against settlement reversals. Cheap to implement (1 cron). Admin can set to 0 to disable. |
| 8 | **Office authorization: any driver/seller at the staff's assigned office.** Staff can process for any driver/seller, but only at the office they're assigned to. | Drivers and sellers may legitimately travel; the risk surface is the staff, not the customer. Mirrors sub-project D's `OrderPolicy::receiveReturnByOffice` pattern. |
| 9 | **Admin reversal via correcting settlement** (immutable original + opposite-direction row). **Reversal allowed only while every contributing earning is still `pending_clearance`** — once any earning is `available` or `paid_out`, reversal is blocked. | Immutability is the standard for financial records. Beyond the clearance window, the seller could have already withdrawn; chasing them is a support case, not a software feature. |
| 10 | **Identity verification at office counter is visual only** — staff matches name + phone + (optionally) national ID. No payout codes, no OTP, no app-side interaction required from the seller. | Per user direction — settlement is in-person and physical; codes are over-engineering for what's already a face-to-face transaction. |
| 11 | **Per-order partial payout supported.** Seller can collect a subset of their `available` earnings in one visit (leave others for next time). | Real-world: sellers may want to leave smaller amounts to accumulate or take only specific orders' proceeds. Easy to support given the per-row earning model. |
| 12 | **`seller_earnings` row is created at delivery time** (in `CodeVerificationService` or `FailedDeliveryService` happy path), NOT at order creation | Row represents an earning that physically exists. Cancelled or failed orders never spawn a row, no cleanup needed. |
| 13 | **No driver settlement reversal beyond the clearance window.** Same constraint as #9 from the driver side. | If the driver has already left and any contributing earning paid out, the only path is out-of-band ops. |

---

## 4. State Machines

### 4.1 Driver Settlement (immutable, no machine)

Each `Settlement` row is terminal at insert:

- `Completed` — the only status written in normal flow.
- `Cancelled` — only ever set by `SettlementReversalService` on the *original* row, AND simultaneously a new "correcting" `Completed` row is written with the opposite cash + bucket movements.
- `Disputed` — stays in the enum for forward compatibility; never written in the current flow (disagreement = no row per #4).

### 4.2 Seller Earnings (new `seller_earnings` row, 1:1 with sale orders)

```
                         (row created by CodeVerificationService at delivery)
                                       ↓
                            pending_settlement
                                       ↓  (SettlementService flips on commit)
                            pending_clearance
                                       ↓  (ClearSellerEarningsJob daily cron, after payouts.clearance_hours)
                            available
                                       ↓  (SellerPayoutService flips when staff hands over cash)
                            paid_out  (terminal)
```

Reverse transitions only under correcting-settlement (per #9): `pending_clearance → pending_settlement`. No other reverse paths.

---

## 5. Schema Changes

### 5.1 New table: `seller_earnings`

```php
Schema::create('seller_earnings', function (Blueprint $table) {
    $table->id();
    $table->ulid('public_id')->unique();

    $table->foreignId('order_id')
        ->unique()                                  // 1:1 with sale orders
        ->constrained()
        ->cascadeOnDelete();

    $table->foreignId('seller_user_id')             // denorm of orders.sender_user_id for fast queries
        ->constrained('users')
        ->restrictOnDelete();

    $table->decimal('amount', 12, 2);               // snapshot of item_price - commission_amount

    $table->string('status')->index();              // pending_settlement | pending_clearance | available | paid_out

    $table->timestamp('cleared_at')->nullable();    // settlement → pending_clearance
    $table->timestamp('available_at')->nullable();  // cron → available
    $table->timestamp('paid_out_at')->nullable();

    $table->foreignId('paid_by_staff_id')->nullable()
        ->constrained('users')->nullOnDelete();

    $table->foreignId('seller_payout_id')->nullable()
        ->constrained('seller_payouts')->nullOnDelete();

    $table->text('notes')->nullable();

    $table->timestamps();
    $table->softDeletes();

    $table->index(['seller_user_id', 'status']);    // seller-dashboard hot path
    $table->index(['status', 'cleared_at']);        // cron hot path
});
```

### 5.2 Modify `seller_payouts`

The existing table was scaffolded around a request → admin-approve → pay flow. The simplified flow has no requests and no approvals — the office staff creates the row at the moment of cash handover. Migrations to apply:

- **DROP** `approved_at`, `approved_by_admin_id`
- **DROP** `rejected_at`, `rejected_by_admin_id`, `rejection_reason`
- **DROP** `requested_at` (no separate request moment exists)
- **RENAME** `paid_by_admin_id` → `paid_by_staff_id`
- **Keep** `status` column. Only `paid` is written in normal flow; `cancelled` is reserved for the admin correcting-payout flow if we ever build it (out of scope here).

The `SellerPayoutStatus` enum loses the obsolete cases — only `Paid` and `Cancelled` remain.

### 5.3 No changes to `orders`

Originally proposed during brainstorm; rejected per #6. The relationship is `Order::sellerEarning(): HasOne(SellerEarning)`.

### 5.4 No changes to `settlements` or `settlement_orders`

Existing tables already support the locked flow.

---

## 6. Services

### 6.1 `SettlementService`

**Sole writer of `settlements`, `settlement_orders`, and the corresponding `driver_account_transactions`.**

Public methods:

- `preview(User $driver): SettlementPreview` — read-only, returns current three-bucket snapshot + net + list of `pending_settlement` earnings that would be cleared. No locks, no writes. Used by the preview endpoint.
- `process(User $driver, User $staff, OfficeLocation $office, string $cashReceived, string $cashPaid, ?string $notes): Settlement` — the atomic commit. Inside one DB transaction:
  1. `lockForUpdate` on `driver_accounts` row.
  2. Snapshot three bucket values.
  3. Compute `expectedNet = cash_to_deposit + debt_balance - earnings_balance`.
  4. Compute `actualNet = cashReceived - cashPaid`.
  5. If `actualNet > expectedNet` → throw `SettlementExcessException` (mapped to 422 by the controller).
  6. If `actualNet == expectedNet` → match.
  7. If `actualNet < expectedNet` → acknowledged shortage; `shortage = expectedNet - actualNet`.
  8. Write the `Settlement` row with all `*_cleared` columns populated to the snapshot values, `shortage_amount` if any, `excess_amount` always 0.
  9. Write three (or fewer, skipping zero-amount ones) `driver_account_transactions` — one per non-zero bucket, with reasons `Settlement` for the cleared amounts. If shortage, an extra `SettlementShortage` reason row tracks the debt_balance addition.
  10. Mutate driver_account: `cash_to_deposit = 0`, `earnings_balance = 0`, `debt_balance = shortage` (becomes shortage if any, else 0).
  11. For each `pending_settlement` earning belonging to this driver's delivered sale orders, flip to `pending_clearance` + stamp `cleared_at = now()`, insert one row into `settlement_orders` pivot per contributing order (`amount_contributed = earning.amount`).
  12. Update `lifetime_*` aggregates on `driver_accounts`.

If the driver has all three buckets at zero → throw `EmptySettlementException` → 422 "no buckets to settle."

### 6.2 `SellerPayoutService`

**Sole writer of `seller_payouts`. Mutates `seller_earnings` rows from `available → paid_out`.**

Public methods:

- `availableEarningsFor(User $seller): Collection<SellerEarning>` — for the office lookup endpoint and seller dashboard.
- `process(User $seller, User $staff, OfficeLocation $office, Collection<SellerEarning> $earnings, string $totalForSanityCheck): SellerPayout` — atomic commit. Inside one DB transaction:
  1. `lockForUpdate` on each earning row.
  2. Re-check: every earning belongs to `$seller`, every earning's status is `available`, no earning has a `seller_payout_id`.
  3. Sum `amount` across earnings → must equal `$totalForSanityCheck` exactly (server-side trust, client-side mismatch = 422).
  4. Insert `seller_payouts` row (`status = paid`, `amount`, `paid_at = now()`, `paid_by_staff_id`, `office_id`).
  5. Bulk update each earning: `status = paid_out`, `paid_out_at = now()`, `paid_by_staff_id`, `seller_payout_id`.
  6. Bulk insert `seller_payout_orders` pivot rows (one per earning, linking `seller_payout_id ↔ order_id` with `amount_contributed`).

### 6.3 `SettlementReversalService`

**Admin-only. Issues the correcting settlement per #9.**

Public method:

- `reverse(Settlement $original, User $admin, string $reason): Settlement` — atomic. Inside one DB transaction:
  1. `lockForUpdate` on the original settlement + each contributing earning.
  2. Validate: every contributing earning's status is still `pending_clearance`. If any has progressed (`available` or `paid_out`), throw `SettlementNotReversibleException` → 422.
  3. Validate: original status is `Completed`.
  4. `lockForUpdate` on `driver_accounts`.
  5. Write the new `Settlement` row (the "correcting" entry) with reversed cash + bucket movements. Status `Completed`. `notes` includes `"Reversal of {original.public_id}: {reason}"`.
  6. Write reversed `driver_account_transactions` (reason `ManualAdjustment`, notes pointing at both settlements).
  7. Mutate `driver_accounts`: undo the original bucket clears (re-add `cash_to_deposit`, `earnings_balance`, subtract `debt_balance` shortage if any).
  8. Flip the contributing earnings back: `pending_clearance → pending_settlement`, NULL out `cleared_at`.
  9. Soft-delete the contributing `settlement_orders` pivot rows (audit-preserving).
  10. Update original settlement: `status = Cancelled`, `notes` appended with `"Reversed by {correcting.public_id}: {reason}"`.

### 6.4 `ClearSellerEarningsJob`

**Daily cron.** Scheduled in `routes/console.php` as `seller-earnings.clearance` with `withoutOverlapping()`.

```
SELECT * FROM seller_earnings
WHERE status = 'pending_clearance'
  AND cleared_at <= now() - interval (payouts.clearance_hours, default 48)
```

Wrapped in `Cache::lock('seller-earnings:clear', 90)->block(5, ...)`. Cursor-iterate, per row: `status = available`, `available_at = now()`. Logs `available_count` at job end. Per-row try/catch + log so one bad row doesn't kill the batch (mirrors `AbandonStaleOrdersJob`).

### 6.5 Integration points (existing services touched)

- `CodeVerificationService::confirmDelivery()` — at the moment of delivery success, if `order.order_type IN (p2p_sale, merchant_delivery)` AND `order.item_price > 0`, insert a `seller_earnings` row with `status = pending_settlement`, `amount = order.item_price - order.commission_amount`. Done inside the existing DB transaction.
- `FailedDeliveryService` — must NOT spawn a `seller_earnings` row for failed deliveries (item never sold; the sale didn't happen). Existing service is untouched. Verified.
- `DriverAccountLedgerService` — extend with one helper if needed for the reversal flow's bucket re-credits; the existing `applyFee` / `applyDeliveryCompletionCredit` don't cover reversal semantics. To be designed in the plan.

---

## 7. Endpoints (15 total)

### Driver — read-only history (2)

| Method | URL | Auth | Notes |
|---|---|---|---|
| GET | `/api/driver/settlements` | sanctum + role:driver | Paginated, newest first |
| GET | `/api/driver/settlements/{public_id}` | sanctum + role:driver + ownership | Receipt detail with contributing orders |

### Office staff — settlement (3)

| Method | URL | Auth | Notes |
|---|---|---|---|
| GET | `/api/office/drivers/{driver_public_id}/settlement-preview` | sanctum + role:office_staff + active office assignment | Read-only, no DB write |
| POST | `/api/office/settlements` | sanctum + role:office_staff + active office assignment | Atomic commit per §6.1 |
| GET | `/api/office/settlements` | sanctum + role:office_staff + active office assignment | Lists settlements processed at staff's assigned office |

### Office staff — seller payouts (3)

| Method | URL | Auth | Notes |
|---|---|---|---|
| GET | `/api/office/seller-payouts/lookup?phone=...` | sanctum + role:office_staff + active office assignment | Returns seller info + available earnings + total |
| POST | `/api/office/seller-payouts` | sanctum + role:office_staff + active office assignment | Atomic commit per §6.2 |
| GET | `/api/office/seller-payouts` | sanctum + role:office_staff + active office assignment | Lists payouts processed at staff's assigned office |

### Seller — read-only (3)

| Method | URL | Auth | Notes |
|---|---|---|---|
| GET | `/api/me/earnings` | sanctum | Breakdown: pending_settlement / pending_clearance / available totals + per-order `available` list |
| GET | `/api/me/seller-payouts` | sanctum | Pickup history |
| GET | `/api/me/seller-payouts/{public_id}` | sanctum + ownership | Receipt detail |

### Admin (4)

| Method | URL | Auth | Notes |
|---|---|---|---|
| GET | `/api/admin/settlements` | sanctum + role:admin | Filters: driver, office, date range, status |
| GET | `/api/admin/settlements/{public_id}` | sanctum + role:admin | Detail |
| POST | `/api/admin/settlements/{public_id}/reverse` | sanctum + role:admin | Per §6.3. 422 if any contributing earning past `pending_clearance` |
| GET | `/api/admin/seller-payouts` | sanctum + role:admin | Filters: seller, office, date range |

---

## 8. Policies

- `SettlementPolicy::process(User $staff)` — staff has `office_staff` role AND at least one active `office_staff_assignments` row
- `SettlementPolicy::previewForDriver(User $staff, User $driver)` — same as `process`
- `SettlementPolicy::viewByDriver(User $driver, Settlement $s)` — `$s->driver_id === $driver->id`
- `SettlementPolicy::viewByOffice(User $staff, Settlement $s)` — staff has active assignment at `$s->office_id`
- `SettlementPolicy::reverse(User $admin, Settlement $s)` — admin role only
- `SellerPayoutPolicy::process(User $staff)` — same as `SettlementPolicy::process`
- `SellerPayoutPolicy::viewBySeller(User $seller, SellerPayout $p)` — `$p->user_id === $seller->id`
- `SellerPayoutPolicy::viewByOffice(User $staff, SellerPayout $p)` — staff has active assignment at `$p->office_id`
- `SellerEarningPolicy::viewBySeller(User $seller, SellerEarning $e)` — `$e->seller_user_id === $seller->id`

The office-assignment check helper (`Order::orderInUsersOffice`-equivalent) already exists in `OrderPolicy` from sub-project D — extract into a shared trait or `User::isAssignedToOffice(int)` helper during the plan phase.

---

## 9. Platform Settings (new)

Seeded by extending the existing `OrderLifecyclePlatformSettingsSeeder` (or a new `SettlementPlatformSettingsSeeder` if the existing one would balloon).

| Key | Default | Description |
|---|---|---|
| `payouts.clearance_hours` | `48` | Hours between settlement and earning becoming `available`. |
| `payouts.min_amount` | `20.00` | Minimum total for any single seller-payout transaction (sanity floor — sellers can hold smaller amounts but can't withdraw <`min`). |
| `payouts.allow_partial` | `true` | Whether seller may collect a subset of their `available` earnings (vs. all-or-nothing). |
| `settlement.reverse_window_hours` | NULL | If set, blocks admin reversal beyond N hours after the original settlement (in addition to the "any earning past pending_clearance" check). Optional belt-and-suspenders. |

---

## 10. Enums (new + extended)

### New: `SellerEarningStatus`

```php
enum SellerEarningStatus: string {
    case PendingSettlement = 'pending_settlement';
    case PendingClearance  = 'pending_clearance';
    case Available         = 'available';
    case PaidOut           = 'paid_out';
}
```

### Modified: `SellerPayoutStatus`

Drop unused cases — keep only `Paid` and `Cancelled` (`Cancelled` reserved for admin reversal of payouts, out of scope here but enum-ready).

### Existing, unchanged: `SettlementStatus`, `DriverAccountTransactionReason`

The `DriverAccountTransactionReason` enum already has `Settlement`, `SettlementShortage`, `SettlementExcess`, `Payout`, `ManualAdjustment` — sufficient for everything here.

---

## 10b. Money Math

All monetary calculations use `bcmath` with scale 2 throughout (e.g. `bcadd`, `bcsub`, `bcmul`, `bccomp`). Float arithmetic is forbidden per spec Critical Rule. The pattern mirrors `DriverAccountLedgerService` (existing) and `StorageFeeCalculator` (existing) — all comparisons via `bccomp($a, $b, 2)`, never `==`.

---

## 11. Concurrency & Locking

- `SettlementService::process()` — `lockForUpdate` on `driver_accounts`. Implicitly serializes settlements per driver (matches reality — a driver can only physically be at one counter at a time).
- `SellerPayoutService::process()` — `lockForUpdate` on each contributing `seller_earnings` row + re-check `seller_payout_id IS NULL` and `status = 'available'` inside the lock. Prevents the same earning being in two simultaneous payouts (theoretical only — same physical seller can't be at two offices at once).
- `ClearSellerEarningsJob` — `Cache::lock('seller-earnings:clear', 90)` to prevent overlapping cron runs.
- `SettlementReversalService::reverse()` — `lockForUpdate` on original `Settlement` + each contributing earning + `driver_accounts`. Same transaction.

---

## 12. Rate Limits

Register in `AppServiceProvider::configureRateLimiters()`:

- `office_settlement` — 60/min per authenticated staff user (settlement is a counter operation, not a request flood)
- `office_payout` — 60/min per authenticated staff user
- `seller_earnings_read` — 30/min per seller (dashboard polling)

---

## 13. Smoke Test Scenarios

Add to `scripts/orders-e2e.php` (currently 17 scenarios from the failed-delivery milestone; bring it to ~25+):

1. **Settlement happy path — match exact.** Driver has 100 in cash_to_deposit, 30 in earnings, 0 in debt. Hands over 70 cash. Settlement records match, all buckets clear, seller earnings flip to `pending_clearance`.
2. **Settlement match with zero net.** cash_to_deposit + debt = earnings exactly. No cash changes hands. All buckets zero out.
3. **Settlement match where platform pays driver.** earnings > cash_to_deposit + debt. cash_paid_to_driver > 0, cash_received = 0.
4. **Settlement acknowledged shortage.** Driver hands less than owed. Shortage flips to `debt_balance`, all original buckets cleared, `SettlementShortage` transaction recorded.
5. **Settlement excess rejected.** Driver hands more than owed. 422, no row created.
6. **Settlement on driver with all zero buckets.** 422 `EmptySettlementException`.
7. **Settlement with no pending sale-order earnings.** Standard-delivery-only driver. Cash bucket clears normally, no `settlement_orders` pivot rows, no `seller_earnings` mutations.
8. **Settlement with multiple sellers' earnings.** Driver carried cash for 3 different sellers' orders. All 3 sellers' earnings flip to `pending_clearance` in one settlement.
9. **Clearance cron flips eligible earnings.** Time travel +49h. Cron runs. Earnings flip `pending_clearance → available`.
10. **Clearance cron skips ineligible.** Earnings <48h old stay `pending_clearance`.
11. **Seller payout happy path — all available.** Seller has 3 available earnings. Staff pays all. All flip to `paid_out`, one `seller_payouts` row, 3 `seller_payout_orders` pivot rows.
12. **Seller payout partial.** Seller has 3 available; staff pays 2 of them. The third stays `available`.
13. **Seller payout sanity-check mismatch.** Staff submits `total = 100` but selected earnings sum to 120. 422.
14. **Seller payout below minimum.** Total < `payouts.min_amount`. 422.
15. **Seller payout at non-assigned office.** Staff at office A tries to pay a seller whose request is at office B. 403.
16. **Admin reversal happy path.** Settlement is fresh, all earnings still `pending_clearance`. Reversal creates correcting row, earnings flip back to `pending_settlement`, driver buckets restored.
17. **Admin reversal blocked — earning past clearance.** Any one earning has progressed to `available`. 422.
18. **Office cross-driver settlement.** Staff at office A settles a driver normally onboarded at office B (legitimate roaming). Allowed per #8.
19. **Concurrent settlement attempts on same driver.** Two simultaneous calls — first acquires the `driver_accounts` lock and commits; the second blocks until release, then proceeds, sees buckets at zero, and gets 422 `EmptySettlementException`.

---

## 14. Documentation Updates Required

After implementation:

- `docs/CLAUDE.md` — Current Project State block updated with this milestone, endpoints table, locked decisions
- `docs/SYSTEM_SPECIFICATION.md` — new §17 entry (settlement milestone)
- `docs/CODEX.md` — Slice entry if Codex implements any slice of this

---

## 15. Open Questions (to resolve during planning)

- **Settlement reversal driver notification:** does the driver get a push/in-app alert that their settlement was reversed? Probably yes, but the notification milestone hasn't shipped. For now: silent reversal, driver sees it next time they pull settlement history.
- **Payout reference printing:** the existing `seller_payouts.public_id` ULID is the receipt reference. Format for display (e.g. short-hash, prefix `PO-`, etc.) is a UI concern, not spec-locked.
- **`settlement.reverse_window_hours` default value:** leave NULL (no time cap; only the "any earning past clearance" check applies) or set to 168 (1 week) as a hard ceiling? Defer to plan.

---

## 16. Implementation Split

Both Claude and Codex write code during implementation. Task allocation TBD during plan phase, but broadly:

- Schema migrations + enums + models — either
- `SettlementService` + `SettlementReversalService` — needs careful reasoning over multi-table locks; lean Claude
- `SellerPayoutService` + `ClearSellerEarningsJob` — straightforward, lean Codex
- Endpoints + FormRequests + Resources + Policies — split by area (office vs admin vs seller vs driver)
- Smoke test scenarios — last, after services land

---

**End of design.**
