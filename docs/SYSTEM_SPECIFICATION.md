# 🚀 Delivery & Logistics Platform — System Specification

**Version:** 1.0
**Last Updated:** May 2026
**Status:** Architecture Locked, Implementation In Progress

---

## 📑 Table of Contents

1. [Project Overview](#1-project-overview)
2. [Core Actors](#2-core-actors)
3. [Order Types & Flows](#3-order-types--flows)
4. [Financial Model](#4-financial-model)
5. [Delivery Verification System](#5-delivery-verification-system)
6. [Returns & Storage](#6-returns--storage)
7. [Office Locations](#7-office-locations)
8. [Order State Machine](#8-order-state-machine)
9. [Driver System](#9-driver-system)
10. [Driver Assignment Logic](#10-driver-assignment-logic)
11. [Cash Settlement](#11-cash-settlement)
12. [Receiver Model](#12-receiver-model)
13. [Notifications & Real-time](#13-notifications--real-time)
14. [Tech Stack](#14-tech-stack)
15. [Database Conventions](#15-database-conventions)
16. [MVP vs Future Scope](#16-mvp-vs-future-scope)
17. [Implementation Progress](#17-implementation-progress)

---

## 1. Project Overview

### Purpose
A scalable hybrid logistics + marketplace platform that supports:
- **Standard Delivery** — sending items between users
- **P2P Sales** — peer-to-peer selling with cash-on-delivery
- **Merchant Deliveries** — shops fulfilling customer orders

### Target Market
**Libya (LYD currency, Arabic primary, phone-first authentication)**

### Core Value Proposition
- Trusted item handoffs via verification codes
- Cash-on-delivery aligned with Libyan payment habits
- Hybrid receiver model (registered users + unregistered guests)
- Geospatial driver matching with PostGIS
- Office-based returns and item retrieval system

---

## 2. Core Actors

### 2.1 Users (Senders/Buyers/Receivers)
- Can send items, sell items, buy items, or receive items
- Phone-first registration (E.164 format: `+218911234567`)
- Optional email
- Multi-role capable (one user can be sender + driver + merchant)

### 2.2 Drivers
- **Face-to-face onboarding required** at office (no self-registration)
- Document verification: National ID (front+back), Driver's License, Vehicle Registration, Selfie, Vehicle Photos (front+back with plate)
- Walk-in registration during office hours (no appointment system)
- Vehicle types: **Car** or **Motorcycle**
- Initial low cash liability, increases with proven track record

### 2.3 Merchants
- Operate storefronts on the platform
- Create delivery orders to customers
- Custom commission rates negotiable per merchant

### 2.4 Office Staff
- Process driver settlements
- Receive returned items
- Confirm seller item retrievals
- Assigned to one or more offices
- Cannot manage drivers globally or change platform settings

### 2.5 Admin
- Full platform control
- Manages stuck orders, disputes, suspensions
- Configures platform settings
- Reviews driver strikes and shortages

---

## 3. Order Types & Flows

### 3.1 Standard Delivery (No Sale)
- Sender creates order, item delivered to receiver
- Sender pays delivery fee (default) — collected at pickup
- Or receiver pays — collected at delivery (configurable)
- No item commission (no sale)
- Platform revenue: 2% of delivery fee

### 3.2 P2P Sale
- Seller (sender) lists item for sale
- Buyer (receiver) pays item price + delivery fee
- Item price always paid in cash to driver at delivery
- Delivery fee can be paid via wallet or cash by buyer
- Platform revenue: item commission + 2% of delivery fee

### 3.3 Merchant Delivery
- Merchant creates order, customer receives
- Customer pays at delivery (cash) or pre-paid (future digital)
- Same commission structure as P2P (custom rates negotiable)

---

## 4. Financial Model

### 4.1 Currency
- **LYD (Libyan Dinar)** — single currency for MVP
- Storage: `decimal(12, 2)` precision
- Multi-currency support: deferred to future

### 4.2 Commission Structure

**Item Commission (sales orders only):**
- Default: **0% at MVP launch** (architecture supports any rate)
- Architecture supports flat % with minimum floor
- Custom rates per merchant (negotiable, admin-configurable)
- Snapshotted on order at creation time

**Driver Fee Cut (all delivery types):**
- **2% on every delivery** (uniform across order types)
- Optional minimum floor (e.g., 0.10 LYD)
- Sole revenue stream at MVP launch

**Future Promotions/Discounts:**
- Architecture supports `discount_amount` and `discount_type` fields
- Not active in MVP

### 4.3 Rate Hierarchy
```
Platform Default → Merchant Override → Driver Override → Order Snapshot
```

All rates and amounts **snapshotted on the order at creation time** — never recalculated. Protects historical accuracy if rates change.

### 4.4 Delivery Fee Payer

| Order Type | Default Payer | Configurable |
|---|---|---|
| Standard delivery | Sender (at pickup) | Yes — receiver also possible |
| P2P sale | Receiver | No |
| Merchant sale | Receiver | No |

Database: `delivery_fee_payer` enum on orders (`sender`, `receiver`)

### 4.5 Payment Methods for Delivery Fee

**MVP: Cash only**
- Standard delivery, sender pays → collected at pickup
- Standard delivery, receiver pays → collected at delivery
- Sales orders → collected at delivery with item price

**Future (architecture ready):**
- Wallet pre-payment (top-up via Plutu or similar Libyan gateway)
- User can choose wallet or cash at order creation
- Database column `delivery_fee_payment_method` enum (`wallet`, `cash`)

### 4.6 Item Price (Sales Orders)
- **Always paid in cash** at delivery
- Driver collects, settles with platform
- Never digital in MVP
- Buyer pays cash directly to driver

### 4.7 Driver Account — Three Buckets

Drivers' financial state tracked in **three separate, explicit buckets**:

| Bucket | Meaning | Always Sign |
|---|---|---|
| `cash_to_deposit` | Cash collected from buyers, owed to platform | Positive |
| `earnings_balance` | Delivery fees earned (digital), owed to driver | Positive |
| `debt_balance` | Money driver owes (cancellation fees, shortages, fines) | Positive |

**Net position for driver dashboard:**
```
Net = earnings_balance - debt_balance
(Positive = platform owes driver, Negative = driver owes platform)
```

**Auto-debt offset:** When driver earns, system first deducts from debt_balance before adding to earnings_balance.

### 4.8 User Wallet Buckets (Future)

```
available_balance  → withdrawable
pending_balance    → from sales, awaiting clearance
debt_balance       → owed for failed deliveries, storage fees
```

If user has debt → block withdrawals until cleared (soft ban via `account_status = suspended_unpaid_fees`).

### 4.9 Pending → Available Timing (Sellers)

```
Order delivered (code entered)
  → status: pending_settlement
Driver settles cash at office
  → status: pending_clearance
48 hours pass (admin-configurable)
  → status: available
```

Platform never pays out money it doesn't have. Sellers see status at every stage.

### 4.10 Payouts

- Seller earnings sit in the seller's **Bavix wallet** until the seller chooses to act on them.
- **On-demand** seller requests cash from the wallet (admin manually approves, 1-3 business days).
- **Single payout method: cash pickup at office.** The seller selects the pickup office at request time, admin approves, seller comes in person, agent debits the Bavix wallet by the requested amount and hands over physical cash. **No bank transfers, no off-platform disbursements.** The `payout_method` discriminator is retained at the schema level so future methods (e.g. mobile money) can be added without a migration.
- Sellers may leave their balance in the wallet indefinitely — there is no forced payout cadence. The wallet itself acts as the seller's holding account.
- Minimum payout: 20 LYD (admin-configurable in `platform_settings`).
- Partial payouts are permitted — a seller may request any amount ≤ their available wallet balance and ≥ the minimum. Full-balance withdrawal is the typical case but not enforced.
- All payouts logged with full audit trail (request → approval → cash handover → completion, or rejection).

### 4.11 Driver Cancellation Fee

- **Strike + fee applied** to driver's `earnings_balance`
- If insufficient balance → tracked as `debt_balance`
- Fee goes to **platform** (not user)
- User compensation handled by support team case-by-case
- Amount: fixed (specific number tunable)

### 4.12 Wallet Implementation

**Hybrid approach:**
- **Bavix Laravel Wallet** for user wallets (single-balance, top-up + spend)
- **Custom implementation** for driver accounts (3 separate buckets, complex business rules)

Reasoning: Bavix excels at single-balance wallets with race-condition safety. Driver accounts have unique multi-bucket logic better suited to custom code.

---

## 5. Delivery Verification System

### 5.1 Pickup Code
- Generated at order creation, given to **sender**
- Driver enters code at pickup → status: `picked_up`
- **Fallback:** within 500m geofence + sender confirmation in app
- Rate-limited attempts (3-5 max)

### 5.2 Delivery Code
- Generated at order creation, given to **receiver**
- Driver enters code at delivery → status: `delivered`
- **Code entry = point of no return** for sales (no take-backs after this)
- Rate-limited attempts

### 5.3 Code Specifications
- 4-6 digit numeric (no letters to avoid confusion)
- Stored on order row (hashing optional)
- Generated at order creation, never regenerated
- Pickup methods logged: `code`, `geofence_confirmation`, `admin_override`

### 5.4 Database Fields
```
orders:
  - pickup_code, pickup_code_attempts, picked_up_method
  - delivery_code, delivery_code_attempts, delivered_method
```

---

## 6. Returns & Storage

### 6.1 Failed Delivery Triggers
- Receiver refuses item
- Receiver unreachable / no answer
- Wrong or invalid address
- Item damaged or rejected on inspection
- Driver fault (rare, admin overrides)

### 6.2 Failed Delivery Financial Rules

| Scenario | Driver Paid By | Default |
|---|---|---|
| Receiver refuses | Seller | Yes |
| Receiver unreachable | Seller | Yes |
| Address invalid (sender's fault) | Sender | Yes |
| Driver/platform fault | Platform absorbs | Admin override |

**Driver always paid for delivery attempt** (they did the work).

### 6.3 Return Flow
```
delivery_failed → returning_to_office → at_office → 
  → retrieved_by_seller (paid fees, signed)
  → abandoned (after 30 days, admin handles)
```

### 6.4 Storage Policy
- **First 5 days:** Free storage at office
- **Day 6 onwards:** Flat daily storage fee (admin-configurable)
- **Day 30:** Item considered abandoned, admin handles disposal

### 6.5 Retrieval Process
- Seller comes to office during open hours
- Pays accrued fees (delivery + storage) in **cash at office**
- No retrieval without payment
- **Account suspended** (`suspended_unpaid_fees`) if debts unpaid

### 6.6 Database Fields
```
orders:
  - return_office_id (FK)
  - returned_to_office_at, retrieved_by_seller_at, abandoned_at
  - return_reason, return_fault enums
```

---

## 7. Office Locations

### 7.1 Multi-Office Architecture
**Multi-office ready from day one**, even if MVP launches with one office.

### 7.2 Database Structure
```
office_locations:
  - id, name, address
  - latitude, longitude (PostGIS)
  - region_id (FK to regions)
  - phone, operating_hours (JSON)
  - is_active, capacity (optional)
  - manager_user_id (FK)
```

### 7.3 Service Area Layered Control

**Three layers of geographic control:**

1. **Service Areas** — maximum platform coverage (PostGIS POLYGONs)
2. **Regions** — operational regions within service areas (linked to offices)
3. **Driver Region Assignments** — drivers limited to specific regions

**Validation:**
- Order pickup must be in active service area
- Driver must be in active service area to go online
- Driver matching prefers same-region drivers

---

## 8. Order State Machine

### 8.1 Status List

**Pre-Pickup:**
- `created` — Just created, validating
- `awaiting_driver` — Searching for driver
- `no_driver_available` — Timeout, admin notified
- `assigned` — Driver accepted

**In Transit:**
- `driver_en_route_pickup` — Heading to sender
- `picked_up` — Item in driver's possession
- `driver_en_route_dropoff` — Heading to receiver
- `delivery_in_progress` — At dropoff location

**Terminal (Happy):**
- `delivered` — Code entered, sale complete

**Failure & Return:**
- `delivery_failed` — Refused/unreachable/invalid
- `returning_to_office` — Driver heading to office
- `at_office` — Item received at office
- `retrieved_by_seller` — Seller picked up
- `abandoned` — 30+ days at office

**Cancellation:**
- `cancelled_by_user` — Sender cancelled
- `cancelled_by_admin` — Admin override
- (No `cancelled_by_driver` — drivers can't self-cancel mid-trip)

### 8.2 Key Transition Rules

- **Driver assignment timeout:** 10 minutes (admin-configurable)
- **Driver mid-trip cancellation:** Not allowed via app — must call support
- **User cancellation after pickup:** Allowed, **no fee refund**, item goes to office
- **User cancellation in `awaiting_driver`:** Free
- **User cancellation in `assigned`/`en_route`:** Cancellation fee may apply
- **Pickup code OR 500m geofence:** Required for `picked_up` transition
- **Delivery code:** Required for `delivered` transition

### 8.3 Order Status Logs (Audit Trail)

**Every status transition is logged** in `order_status_logs`:

```
order_status_logs:
  - order_id, from_status, to_status
  - actor_type (user, driver, admin, system, office_staff)
  - actor_id (nullable for system)
  - reason, notes
  - actor_location (PostGIS point — driver's GPS at moment)
  - metadata (JSON for extra context)
  - created_at
```

Permanent records — never deleted or modified. Powers dispute resolution.

---

## 9. Driver System

### 9.1 Account States (Layer 1)
- `pre_registered` — Created online, must visit office
- `pending_approval` — Office visited, awaiting admin review
- `approved` — Admin approved, ready to work
- `active` — Currently working driver
- `suspended` — Temporarily blocked
- `banned` — Permanently blocked
- `rejected` — Application rejected

### 9.2 Activity Status (Layer 2 — runtime)
- `offline` — App closed or driver paused
- `online` — Available for order broadcasts
- `on_order` — Currently fulfilling an order

**No `on_break` status** — driver just closes app if they need a break.

### 9.3 Going-Online Checks
- Account is `active` (not suspended)
- GPS enabled and getting signal
- No outstanding cash settlement issues
- `cash_to_deposit` < `max_cash_liability`
- Driver inside active service area

### 9.4 Auto-Offline Triggers
- No GPS received for 5+ minutes
- Driver inactive for 30+ minutes
- Cash liability hits max (blocked from cash orders)
- Account suspended by admin

### 9.5 Cash Liability System
- New drivers: **low initial limit** (~100 LYD)
- Increases with proven track record (~500 LYD after 50 successful deliveries)
- **Soft warnings** at 50%, 80% of max
- **Hard block** at 100% (cannot accept new cash orders)
- Unblocked after settlement at office

### 9.6 Strike System
- **Accept-then-cancel:** automatic strike + fee deducted
- 3 strikes in 30 days → admin review
- 5 strikes → suspension (configurable)
- Reasons logged for every strike
- Admin can void invalid strikes (real emergencies, etc.)

### 9.7 Driver Onboarding (Face-to-Face Required)

**Step 1:** Pre-registration online (basic info, status: `pre_registered`)
**Step 2:** Walk-in office visit during open hours
**Step 3:** Office staff verifies identity, collects documents
**Step 4:** Driver signs platform agreement
**Step 5:** Admin reviews, approves or rejects
**Step 6:** Driver account becomes `active`

**Required documents:**
- National ID (front + back)
- Driver's License
- Vehicle Registration
- Selfie (for face matching)
- Vehicle photos (front + back with plate)

### 9.8 Vehicle Types
- **Car**
- **Motorcycle**

(Other vehicles — van, truck, bicycle — deferred)

### 9.9 Location Tracking

**Two tables for two purposes:**

```
driver_profiles.current_location (PostGIS point)
  → Overwritten on each update
  → For fast nearest-driver queries

driver_locations (history table)
  → Smart filtering: only store if moved 50m+ OR 60s+ since last
  → 7 days full retention
  → Older data aggregated/archived
  → For audit, fraud detection, analytics
```

---

## 10. Driver Assignment Logic

### 10.1 Default Flow: Auto-Broadcast

```
1. Order created
2. System finds eligible drivers (online, in radius, vehicle match, liability OK)
3. Broadcast push notification to all eligible
4. First driver to tap "Accept" wins (atomic UPDATE)
5. Other drivers see "Order taken"
6. Status: assigned
```

### 10.2 Atomic Order Claim
**Concurrent-safe via conditional UPDATE:**
```sql
UPDATE orders 
SET driver_id = ?, status = 'assigned', assigned_at = NOW()
WHERE id = ? AND status = 'awaiting_driver'
```
Only one driver's UPDATE returns affected_rows = 1. Others see "taken."

### 10.3 Radius Expansion Tiers

| Time Elapsed | Radius | Surcharge |
|---|---|---|
| 0 min | 3 km | None |
| 3 min | 5 km | +20% |
| 6 min | 10 km | +50% |
| 10 min | — | Status: `no_driver_available` (admin handles) |

**Surcharge paid by user** (transparent at order creation).
**Surcharge goes 100% to driver** as incentive.

### 10.4 Manual Admin Assignment
**Rare fallback, not default.** Only for stuck orders or special cases.

### 10.5 Vehicle Type Matching

| Item Size | Vehicle Types |
|---|---|
| small | motorcycle OR car |
| medium | motorcycle OR car |
| large | car only |
| xlarge | car only |

### 10.6 Eligibility Query (PostGIS)
```sql
SELECT drivers WHERE:
  - status = 'active'
  - activity_status = 'online'
  - ST_DWithin(current_location, pickup_location, search_radius)
  - vehicle_type IN required_types
  - (cash_to_deposit + estimated_order_cash) <= max_cash_liability
  - last_seen_at >= NOW() - INTERVAL 2 minutes
ORDER BY ST_Distance(current_location, pickup_location)
LIMIT 20
```

---

## 11. Cash Settlement

### 11.1 Settlement Frequency
**Pure flexible** — driver decides when to settle.
Only constraint: blocked from new cash orders if at max liability.

### 11.2 Settlement Process

```
1. Driver visits office during open hours
2. Office agent opens admin panel
3. Agent looks up driver
4. Panel displays:
   - cash_to_deposit (driver owes)
   - earnings_balance (platform owes driver)
   - debt_balance (driver owes from fees)
5. Agent counts cash with driver
6. Confirms or cancels settlement
7. All balances zero out, settlement record created
```

### 11.3 Net Calculation (Two-Way)

Single cash movement covers all three buckets:
```
Driver owes:    cash_to_deposit + debt_balance
Platform owes:  earnings_balance
Net:            (driver owes) - (platform owes)
```

### 11.4 Outcome Handling

| Outcome | Action |
|---|---|
| Match | Confirm settlement, all balances → 0 |
| Excess | Return extra cash to driver immediately |
| Disagreement | **No record created**, no balance changes, driver leaves with cash |
| Acknowledged shortage | Settlement processed for paid amount, shortage → debt_balance |

**Critical principle:** If driver and agent disagree, **nothing changes**. Acts as if visit didn't happen. Forces resolution before any state change.

### 11.5 Receipt
**Digital only** — confirmation in app showing settlement reference, amounts, date, agent name.

### 11.6 Database Structure
```
settlements:
  - driver_id, office_id, processed_by_staff_id
  - cash_received_from_driver, cash_paid_to_driver
  - cash_to_deposit_cleared, earnings_balance_cleared, debt_balance_cleared
  - shortage_amount, excess_amount
  - status (completed, disputed, cancelled)
  - reference_number (ULID for receipts)

settlement_orders (pivot):
  - settlement_id, order_id, amount_contributed
```

---

## 12. Receiver Model

### 12.1 Two Receiver Types

**Type 1: Registered User**
- Phone matches existing user account
- Auto-linked at order creation
- Full app experience: push notifications, in-app tracking, history

**Type 2: Phone-Verified Guest**
- Phone provided, no account
- `guest_recipients` record created
- **Single SMS** at order creation with code + tracking link
- Web tracking page provides all live updates

**Type 3 (anonymous) does NOT exist.** Sender must provide receiver's phone number and location at order creation.

### 12.2 Auto-Classification Logic
```
Sender enters receiver phone
System checks: does this phone match a registered user?
  YES → Type 1 (link to account)
  NO  → Type 2 (create guest_recipient, send SMS)
```

### 12.3 Required at Order Creation
- Receiver phone number (E.164 format)
- Receiver name (so driver knows who to ask for)
- Receiver location (precise pickup point — PostGIS)
- Receiver notes (optional: "ring twice", "leave at gate", etc.)

### 12.4 Snapshot on Order
Even for registered receivers, **snapshot phone/name/address on order itself** — protects against profile changes/deletions.

### 12.5 Guest Conversion Flow
- Final SMS includes "Sign up" CTA
- When guest registers with same phone → auto-merge `guest_recipients` history
- All past deliveries appear in their new account

### 12.6 Web Tracking Page
- Public URL: `/track/{tracking_token}` (UUID/ULID, hard to guess)
- Live driver location, status, code, ETA
- Driver contact (phone — masked in v2)
- Mobile-responsive HTML, no install required
- Powered by WebSocket (Reverb) with polling fallback

### 12.7 Database
```
guest_recipients:
  - id, phone_number (unique, indexed)
  - first_name, last_name (optional)
  - first_received_at, last_received_at
  - total_deliveries
  - converted_to_user_id (nullable, for merged accounts)
  - converted_at

orders:
  - sender_user_id (NOT NULL)
  - receiver_user_id (nullable, if registered)
  - receiver_guest_id (nullable, if guest)
  - receiver_phone, receiver_name (always captured)
  - receiver_location (PostGIS), receiver_address, receiver_notes
  - receiver_type enum (registered_user, guest)
  - tracking_token (ULID, public)
```

---

## 13. Notifications & Real-time

### 13.1 Notification Channels

| Channel | Use Case |
|---|---|
| Push (FCM/APNs) | Real-time alerts, order updates, driver broadcasts |
| In-app | Bell icon, history of all notifications |
| SMS | Type 2 guests (one SMS only at order creation) |
| Email | Critical account events, deferred for MVP |

### 13.2 MVP Must-Haves

**Driver:**
- New order broadcast (push, high priority)
- Cash liability warnings (push + in-app)
- Strike issued (push + in-app)

**Sender (registered):**
- Order status changes (push + in-app)
- Driver assigned + ETA (push + in-app)
- Delivery confirmation (push + in-app)

**Receiver (Type 1):**
- Same as sender, plus delivery code visible in app

**Receiver (Type 2 guest):**
- Single SMS at order creation: code + tracking link
- All updates via web tracking page

**Admin:**
- Stuck orders alert (dashboard)
- Settlement shortage flagged

### 13.3 Push Provider
- **FCM (Firebase Cloud Messaging)** for Android
- **APNs (Apple Push Notification Service)** for iOS
- Direct integration via Laravel notification channels

### 13.4 SMS Provider
- **Deferred decision** — research Libyan options (Plutu, local telcos, Twilio fallback)
- Single SMS per Type 2 order keeps costs manageable

### 13.5 Real-time Tracking (Reverb WebSockets)

**Active period:** From `assigned` through `delivered`/`failed`

**Update frequency:**
- Driver `on_order`: every 10-15 seconds
- Driver `online` (idle): every 30-60 seconds (for nearest-driver queries)

**Reverb Channels:**
```
private:user.{user_id}        → user receives own updates
private:order.{order_id}      → sender + receiver subscribe
private:driver.{driver_id}    → driver broadcasts and status
public:track.{tracking_token} → guest receivers (web page)
```

### 13.6 In-App Notification Center
- Bell icon with unread count
- List of past notifications, mark-as-read
- Standard Laravel `notifications` table

### 13.7 Phone Masking
- **MVP:** Show real phone numbers between driver and receiver
- **v2:** Implement masking via SMS gateway / VoIP proxy

---

## 14. Tech Stack

### 14.1 Backend
- **PHP / Laravel** — core framework
- **REST API** — communication layer
- **Laravel Sanctum** — token-based authentication

### 14.2 Database
- **PostgreSQL** — primary database
- **PostGIS** — geospatial queries (distance, zones, polygons)
- **Docker** — local development environment

### 14.3 Caching & Queues
- **Redis** — caching, queues, real-time pub/sub

### 14.4 Real-time
- **Laravel Reverb** — WebSocket server for live tracking

### 14.5 File Management
- **Spatie Media Library** — handles uploads (avatars, documents, item photos)

### 14.6 Roles & Permissions
- **Spatie Laravel Permission** — role-based access control

### 14.7 Wallet System (Hybrid)
- **Bavix Laravel Wallet** — for user wallets (single balance, top-up + spend)
- **Custom implementation** — for driver accounts (3 separate buckets)

### 14.8 Push Notifications
- **FCM** + **APNs** — direct integration via Laravel channels

### 14.9 Payment Gateway (Future)
- **Plutu (likely)** — primary candidate for Libyan card processing
- Architecture supports multiple providers via abstraction layer

---

## 15. Database Conventions

### 15.1 Primary Keys
- **Auto-increment internal IDs** (`bigIncrements`) for all tables
- **ULID `public_id` columns** added to tables exposed in URLs/APIs
- ULIDs used for tracking tokens, settlement references, payout references

### 15.2 Foreign Keys
- Format: `{singular}_id` (e.g., `user_id`, `order_id`)
- Always indexed
- Cascade rules explicit (cascade, restrict, nullOnDelete)

### 15.3 Naming Conventions
- **Tables:** snake_case, plural (e.g., `driver_profiles`)
- **Pivots:** alphabetical singular (e.g., `driver_region`)
- **Booleans:** `is_*` or `has_*` prefix
- **Timestamps:** `*_at` suffix
- **Enums:** stored as strings, validated by PHP enums (PHP 8.1+)

### 15.4 Money Fields
- All money: `decimal(12, 2)` precision
- Always non-negative for bucket balances (signed values in transaction tables)

### 15.5 Timestamps
- All tables: `created_at`, `updated_at`
- Soft-deleted tables: also `deleted_at`

### 15.6 Soft Deletes
**Use soft deletes for:**
- users, drivers, orders
- All financial records (audit trail)

**Use hard deletes for:**
- notifications (after read + 30 days)
- driver_locations history (after retention period)
- ephemeral data

### 15.7 PostGIS
- **Points** (lat/lng): `geography(POINT, 4326)` — uses spherical geometry, accurate distance
- **Polygons** (regions, service areas): `geography(POLYGON, 4326)`
- **SRID 4326** — WGS84, standard GPS coordinate system

### 15.8 Charset
- PostgreSQL default UTF8

---

## 16. MVP vs Future Scope

### 16.1 MVP Includes
- ✅ User authentication (phone-first, OTP optional)
- ✅ Driver onboarding (face-to-face)
- ✅ Order creation & lifecycle
- ✅ Cash-only payment (item + delivery fee)
- ✅ Driver assignment via auto-broadcast
- ✅ PostGIS geospatial matching
- ✅ Cash settlement at offices
- ✅ Returns & 30-day storage policy
- ✅ Pickup + delivery code verification
- ✅ Two-tier receiver model (registered + guest)
- ✅ Real-time tracking (driver location, order status)
- ✅ Single SMS for guest receivers
- ✅ Push notifications (FCM/APNs)
- ✅ Admin dashboard (basic)
- ✅ Strike system for drivers
- ✅ Office staff role
- ✅ Multi-office architecture

### 16.2 Deferred to Future
- ⏳ Wallet top-ups via Plutu/Libyan card gateways
- ⏳ Digital payment for delivery fees
- ⏳ Email notifications (except critical)
- ⏳ Driver tipping system
- ⏳ Promo codes / discount campaigns
- ⏳ Phone number masking for driver-receiver calls
- ⏳ Scheduled future orders
- ⏳ Automated payouts (currently manual admin processing)
- ⏳ Full escrow system for high-value sales
- ⏳ Advanced merchant tools (catalog, inventory)
- ⏳ Rating & review system
- ⏳ Multi-currency support
- ⏳ Driver appointment system
- ⏳ Additional vehicle types (van, truck, bicycle)
- ⏳ Complex analytics dashboards
- ⏳ Driver tier system (premium driver privileges)
- ⏳ Item commission revenue (architecture ready, deferred)

### 16.3 Architecture-Ready (Built but Inactive)
- Wallet schema in place, just not active in UI
- Commission rate fields on orders (set to 0% currently)
- Per-merchant custom commission rates
- Per-driver custom fee cuts
- `delivery_fee_payment_method` enum supports both wallet and cash
- `tip_amount` and `discount_amount` fields ready for future
- `scheduled_for` field ready for future scheduling

---

## 17. Implementation Progress

### 17.1 Build Order (Database Schema)

**Group 1: Foundation** ✅ DONE
- ✅ `users` table (final spec applied — first_name/last_name, public_id ULID, account_status, fcm_token, etc.)
- ✅ Spatie Permission tables + roles seeded (`user`, `driver`, `merchant`, `office_staff`, `admin`)
- ✅ Spatie Media table
- ✅ `platform_settings` table + 21 seeded defaults (timeouts, radii, surcharges, fees, liability limits)
- ✅ PostGIS extension migration via clickbar/laravel-magellan
- ✅ Bavix wallet tables (transactions, transfers, wallets, wallet_purchases) — used by Group 6

**Group 2: Geographic** ✅ DONE
- ✅ `service_areas` (PostGIS Polygon + GIST)
- ✅ `regions` (PostGIS Polygon + GIST, FK to service_area)
- ✅ `office_locations` (PostGIS Point + GIST, ULID public_id, soft deletes)
- ✅ `office_staff_assignments` (pivot)
- ✅ Circular regions↔office_locations FK resolved via separate ALTER migration

**Group 3: User Profiles** ✅ DONE
- ✅ `driver_profiles` (vehicle, status, activity_status, current_location PostGIS Point + GIST, performance counters)
- ✅ `driver_documents` (per-doc-type unique constraint, expiry, admin verification audit)
- ✅ `merchant_profiles` (minimal — invite-only flow, optional default pickup, custom commission overrides)
- ✅ `driver_region` (pivot, drivers limited to operational regions)

**Group 4: Receivers** ✅ DONE
- ✅ `guest_recipients` (ULID public_id, phone-keyed E.164, conversion-to-user back-link)

**Group 5: Driver Operations** ✅ DONE
- ✅ `driver_accounts` (3 buckets: cash_to_deposit, earnings_balance, debt_balance + max_cash_liability + lifetime stats)
- ✅ `driver_account_transactions` (append-only ledger, signed amounts, polymorphic `reference`, balance_after snapshots)
- ✅ `driver_locations` history (append-only, GPS metadata, GIST index, no updated_at, prune target = 7 days)
- ✅ `driver_strikes` (system/admin issuer, voiding workflow, fee_amount, order_id FK deferred to Group 7)

**Group 6: User Wallets** ✅ DONE
- ✅ Bavix integration verified end-to-end (deposit/withdraw/transfer with float API)
- ✅ User implements `Wallet` + `WalletFloat`, uses `HasWallet` + `HasWalletFloat` traits
- ✅ `decimal_places=2` (LYD-correct) — single default wallet auto-created on first balance access

**Group 7: Orders Core** ✅ DONE
- ✅ `orders` table (50+ columns: identity/status/sender/pickup/receiver/merchant/driver/item/financial snapshots/future-ready fields/returns/cancellation/per-status timestamps + GIST on pickup_location and receiver_location)
- ✅ `order_status_logs` (append-only audit trail, polymorphic actor, PostGIS actor_location, JSON metadata)
- ✅ Deferred FK `driver_strikes.order_id` → `orders.id` added

**Group 8: Operations** ✅ DONE
- ✅ `settlements` (per-bucket cleared snapshots, cash_received/paid, shortage/excess, ULID public_id, soft deletes)
- ✅ `settlement_orders` (pivot with `amount_contributed`)
- ✅ `seller_payouts` — **cash-at-office only** (single-method enum kept for future expansion), ULID public_id, full approval/payment/rejection audit trail, restrict-on-delete office FK. Sellers' earnings sit in their Bavix wallet until they request a cash payout at the chosen office.
- ✅ `office_inventory` (per-order physical tracking: shelf location, accrued storage fee, retrieval/abandonment audit)

**Group 9: Future-Ready** ✅ DONE
- ✅ `payment_methods` — saved tokenised payment instruments, encrypted `gateway_token` (Laravel `encrypted` cast), display-safe metadata (brand, last four, expiry), partial unique index `(user_id) WHERE is_default = true AND deleted_at IS NULL` for at-most-one default per user, ULID public_id, soft deletes
- ✅ `topup_requests` — wallet top-up lifecycle row (pending → processing → completed/failed/cancelled/refunded), gateway routing/correlation, soft pointer to Bavix `transactions.id` via indexed `wallet_transaction_id`, partial unique index `(gateway_provider, gateway_transaction_id) WHERE gateway_transaction_id IS NOT NULL`, ULID public_id, soft deletes

### 17.2 Cross-cutting cleanup (2026-05-05)

- **Group 8 payout policy locked**: `seller_payouts` simplified to **cash-at-office only**. Removed `bank_account_details` column, removed `BankTransfer` enum case + `requiresBankDetails()` helper, made `office_id` NOT NULL with `restrictOnDelete`, set `payout_method` default to `cash_at_office`. Sellers' earnings sit in their Bavix wallet until they request a cash payout at a chosen office. The single-case `SellerPayoutMethod` enum is retained for future expansion.

### 17.3 Current Status (2026-05-05)

**All schema groups (1–9) complete.** Database has 28 application tables (plus framework, Spatie, Bavix, Sanctum). PostGIS 3.5.2 active. All migrations idempotent and reversible. All models follow senior backend Laravel style (strict_types, final, scopes, casts, enum-everywhere).

**24 enums** under `app/Enums/`:
- AccountStatus, DriverStatus, DriverActivityStatus, VehicleType, DriverDocumentType, MerchantStatus
- DriverAccountBucket, DriverAccountTransactionReason, DriverStrikeReason, DriverStrikeIssuer
- OrderStatus (16-state machine with `allowedTransitions()` + `canTransitionTo()`), OrderType, ItemSize, ReceiverType
- DeliveryFeePayer, DeliveryFeePaymentMethod, DeliveryFeeStatus
- PickupMethod, DeliveryMethod, ReturnReason, ReturnFault, OrderActorType
- SettlementStatus, SellerPayoutStatus, SellerPayoutMethod (single-case)
- TopupRequestStatus, PaymentMethodType (single-case), PaymentMethodProvider (single-case)

**23 Eloquent models** with end-to-end relationship plumbing, PostGIS scopes (`withinRadiusOf`, `containing`), state-machine helpers, bcmath-precise money math (`net_position`, `settlement_net`, `cashCollectedAtDelivery`, `cashMovement`), Laravel `encrypted` cast for sensitive gateway tokens, partial unique indexes for invariants that can't be expressed with normal uniques (one default payment method per user, one gateway txn id per provider).

**Verified by tinker smoke tests** at every group: enum casts both directions, Magellan Point/Polygon round-trip, deep eager loading, polymorphic morphTo, real PostGIS spatial queries (ST_DWithin, ST_Contains), state machine guards, atomic claim patterns, encrypted-at-rest verification, partial-unique-index enforcement.

### 17.4 Auth milestone (2026-05-07) ✅

Built the full phone-first authentication surface (12 endpoints + 1 profile endpoint) per design doc `docs/superpowers/specs/2026-05-05-auth-design.md` and implementation plan `docs/superpowers/plans/2026-05-05-auth.md`.

**Endpoints shipped:**
- `POST /api/auth/register` — phone+password+optional email; creates user (account_status=active, phone_verified_at=null), assigns `user` Spatie role, issues registration OTP via SmsService.
- `POST /api/auth/otp/{request,verify}` — purpose-namespaced (`registration` | `password_reset`). Verify on registration sets `phone_verified_at`; verify on password_reset mints a single-use 64-char `reset_token` in Redis cache (10min TTL).
- `POST /api/auth/login` — phone+password → Sanctum bearer. Strict gate: 401 `invalid_credentials` for unknown/wrong, 403 `phone_not_verified` only after password matches. Successful login clears per-phone throttle (per-IP persists for stuffing defense).
- `POST /api/auth/logout` — revokes current bearer token only (not all devices).
- `GET /api/auth/me` — returns current user via UserResource (public_id as id, roles array, no password).
- `GET /api/auth/email/verify/{id}/{hash}` — Laravel signed URL; hash check guards against email-changed-after-link.
- `POST /api/auth/email/verify-resend` — sends fresh link to authenticated user's current email.
- `POST /api/auth/password/forgot` — `channel=otp` (anti-enum SMS) or `channel=email` (anti-enum signed link, only sends to verified email).
- `POST /api/auth/password/reset/{otp,email}` — both tracks revoke ALL Sanctum tokens on success.
- `GET|PATCH /api/me/profile` — show + update; email change resets `email_verified_at` and triggers fresh verification link.

**Locked design decisions:**
- Strict phone-verification gating (login blocked until OTP-verified) — phone is the trust anchor for cash-on-delivery.
- OTP: 6-digit numeric, 5min TTL, 5 attempts/code, 3 resends/15min/phone, 10 verifies/15min/phone, Redis-cached, **hashed at rest**.
- Login throttle: 5/15min/phone (cleared on success) + 20/15min/IP (persists). Per-IP defends against credential stuffing.
- Password reset: dual track — OTP-to-phone primary; email-link only when `email_verified_at IS NOT NULL`. Both tracks revoke all Sanctum tokens (security: log out of all devices on password change).
- Anti-enumeration: identical 200 response for unknown vs known accounts on `register`, `forgot`, OTP-request (password_reset purpose). `phone_not_verified` only after password matches (acceptable leak: caller already proved ownership).

**Provider abstractions:**
- `App\Services\Sms\SmsService` interface with three drivers: `LogSmsDriver` (dev, writes to `laravel.log`), `FakeSmsDriver` (tests, captures sends + helpers like `assertSentTo` / `lastCodeFor`), and slot for future provider (`Plutu` likely). Selected via `SMS_DRIVER` env var.
- Mail uses Laravel's built-in mailer with `MAIL_MAILER=log` in dev. Verification emails use `URL::temporarySignedRoute` — no DB-tracked tokens.

**Cross-cutting:**
- 10 new platform_settings rows seeded (OTP knobs + login throttle thresholds + email/password TTLs) — all admin-tunable without deploy.
- 4 named rate limiters in AppServiceProvider: `login`, `otp_request`, `otp_verify`, `forgot_password`, `password_reset_email`. All read thresholds from platform_settings.
- 2 enums added: `AuthErrorCode` (10 cases + `httpStatus()` switch), `OtpPurpose` (2 cases + `cacheKeyFor()`/`smsTemplate()`).
- Localization stubs at `lang/{en,ar}/auth_messages.php` — controllers still emit hardcoded strings; `__()` wiring is a future pass.
- Pint enforced across all auth files (PSR-12 + Laravel preset).

**Two notable bugs caught during smoke testing:**
- `Limit::response()` callback signature is `(Request, array $headers)` not `(Request, int $availableIn)` — wrong signature returns 500 instead of 429.
- Laravel's named-limiter middleware stores counters under `md5($limiterName . $rawKey)`, not the raw key. Controllers wanting to clear (e.g. on successful login) must mirror that hash formula.

**Cleanup:** removed two stale leftover files (`AuthController.php`, `RegisterDriverRequest.php`) that referenced non-existent classes/tables.

### 17.5 Next Steps

1. **Driver onboarding** — pre-registration endpoint (existing user gets `driver_profile` in `pre_registered` state), office staff walk-in confirmation flow, document upload via Spatie Media, admin approval/rejection, auto-create `driver_account` (3 buckets) on approval.
2. **Order creation & lifecycle** — atomic driver claim, status transitions, code verification, settlement.
3. **Office staff operations** — settlement processing, returned-item intake, seller payout cash handover.
4. **Real-time** — Reverb channels for driver location, order status, public tracking page.
5. **Test infrastructure** — promote tinker smoke tests to Pest feature tests against a separate Postgres test database.

### 17.6 Driver onboarding milestone (2026-05-10) ✅

Built the full driver lifecycle per `docs/superpowers/specs/2026-05-07-driver-onboarding-design.md` and `docs/superpowers/plans/2026-05-07-driver-onboarding.md`.

**Endpoints shipped (19 routes across 4 namespaces):**
- `/api/me/driver/*` (2): existing-user pre-registration self-service
- `/api/office/drivers/*` (7): office staff onboarding (lookup, onboard cold walk-in with in-office OTP, document upload/replace/delete, submit for approval, queue listing)
- `/api/admin/drivers/*` (6): admin review/approve/reject/suspend/reinstate + listing/detail
- `/api/driver/*` (4): driver self-service (profile, account, regions)

**Lifecycle state machine:**
```
pre_registered → pending_approval → active ⇄ suspended
                       ↓
                   rejected
```

**Atomic approval side-effects (DB::transaction):** state → active, driver_account created with `max_cash_liability` from `platform_settings.new_driver_max_liability`, `driver` Spatie role assigned, all `driver_documents` marked verified.

**Locked decisions (recap from spec §3):** face-to-face is the trust model (no driver self-uploads), unified flow handles all 3 user-resolution paths, admin-only approval, rejection is a status flip (no reason field), regions are driver-flexible post-approval (empty = all in office service area), single mobile app with API namespaces clean for future split.

**Cross-cutting work in this milestone:**
- New `DriverErrorCode` enum (9 cases + httpStatus()).
- New `DriverProfilePolicy` with `manageInOffice` (Spatie role + office_staff_assignments scope) and `viewOwn`.
- `TestStaffSeeder` (dev-only) seeds `office_staff` (+218910000001) + `admin` (+218910000002) accounts.
- 6 services: `DriverPreregistrationService`, `DriverOnboardingService` (multi-branch resolver — handles cold walk-in OTP flow), `DriverDocumentService`, `DriverApprovalService` (atomic), `DriverStatusTransitionService`, `DriverRegionService`.
- Spatie media collections registered on User (one single-file collection per `DriverDocumentType` — 8 collections total). Linkage to `driver_documents` is by convention (no FK column, no migration needed).
- Spatie role/permission middleware aliases registered in `bootstrap/app.php`.

**Bug fixes caught during build:**
- `phone_verified_at` / `email_verified_at` were missing from User's `$fillable` — mass assignment silently dropped them. Added.
- Spatie's `acceptsMimeTypes()` content-sniffing rejected test uploads with empty content. Removed from User's media collection registration; mime allowlist enforced at FormRequest layer instead.

**E2E smoke test verified 16 scenarios:** cold walk-in → OTP verify → 7 document uploads → submit → admin approve (with all atomic side-effects) → driver pulls profile/account/regions → suspend → reinstate.

### 17.8 Order lifecycle milestone (2026-05-12) ✅ — A+B + pre-pickup C

Built the order lifecycle happy path plus the pre-pickup half of sub-project C per `docs/superpowers/specs/2026-05-12-order-lifecycle-design.md`, `docs/superpowers/plans/2026-05-12-order-lifecycle.md`, and `docs/CODEX.md` (Slices 1–10).

**Endpoints shipped (22 routes across 5 namespaces):**
- `/api/orders/*` (2): quote + create
- `/api/me/orders/*` (5): list/show/retry/cancel/confirm-pickup-geofence
- `/api/track/{token}` (1): public guest tracking
- `/api/driver/*` (3): go-online/go-offline/location
- `/api/driver/orders/*` (6): broadcast/current/claim/confirm-pickup/arrived-dropoff/confirm-delivery
- `/api/admin/orders/*` (5): index/show/assign/unassign(driver-fault aware)/cancel

**State machine driven through `StateTransitionService`** (sole writer of `orders.status` except for the two atomic conditional UPDATEs in `ClaimService` + `AdminAssignmentService::assign` where the `driver_id IS NULL` race guard requires owning the UPDATE statement). Every transition writes one `order_status_logs` row and dispatches an `OrderStatusChanged` event.

**Locked decisions (recap from spec §3):** per-region flat fee with admin-tunable item-size modifiers + per-km surcharge via `platform_settings`; quote-then-create with 5-min signed `quote_token` (HMAC-SHA256, namespaced); polling broadcast (push deferred to milestone #3); atomic conditional UPDATE for claim (§10.2); hybrid auto + explicit transitions with collapsed `display_status` for clients; 6-digit pickup/delivery codes encrypted at rest with `codes.enforce_pickup` / `codes.enforce_delivery` kill-switch flags (explicit Critical Rule 9 exception); sender retry resets tier 1; admin manual assign/unassign with `force=true` softening on vehicle/region mismatches (liability headroom is hard-blocked); tier escalation is silent column update (only the `no_driver_available` flip is logged).

**Sub-project C pre-pickup tail (Slice 10):** Sender pre-pickup cancel allowed from `awaiting_driver` (free), `no_driver_available` (free), `assigned`/`driver_en_route_pickup` (fee from `cancellation.user_pre_pickup_fee`). Admin can cancel from any pre-pickup operational state. Admin unassign supports `driver_fault=true` → creates `driver_strikes(reason=accept_then_cancel, issued_by=system)` and applies fee through `DriverAccountLedgerService` (debits earnings first, remainder to `debt_balance`; driver flipped offline). Post-pickup cancel rejects until sub-project D lands the return-to-office flow.

**Cross-cutting work:**
- New `OrderErrorCode` enum (34 cases + `httpStatus()`).
- New `OrderPolicy` (`view`, `act`, `retryByUser`, `cancelByUser`, `confirmGeofenceBySender`).
- New `OrderStatusChanged` event dispatched on every transition.
- New `driver_presence_logs` table for online/offline audit (events: `went_online`, `went_offline`, `auto_offline`; reasons: `manual`, `gps_lost`, `idle`).
- New `QuoteToken` HMAC signer namespaced as `quote_token|APP_KEY`.
- New `OrderDisplayStatus` value class collapsing the 16-state machine into 7 client-facing surfaces.
- New `DriverAccountLedgerService` for strike-fee application with earnings → debt overflow.
- Settings seeder adds 24 platform_settings keys.

**Background workers (scheduled `everyMinute` in `routes/console.php`):**
- `EscalateBroadcastingOrdersJob` — tier-2 (+20%) at 3 min, tier-3 (+50%) at 6 min, → `no_driver_available` at 10 min.
- `AutoOfflineIdleDriversJob` — flips online drivers offline on GPS-lost (5 min) or idle (30 min); skips mid-trip drivers.

**Schema additions:**
- `regions.base_fee` (decimal 12,2, default 0)
- `orders.pickup_geofence_confirmed_at` (timestamp, nullable)
- `driver_presence_logs` (new table)
- `orders.pickup_code` and `delivery_code` cast as `encrypted` (no schema change; cast added)

**E2E smoke verified 9 scenarios:** `scripts/orders-e2e.php` — happy-path delivery (quote → create → broadcast → claim → pickup → arrived → delivery with driver bucket assertions); tier escalation + timeout; sender retry; sender free-cancel from no_driver_available; admin manual assign + unassign; driver auto-offline; Slice 10 — pre-pickup user cancel (free + fee), admin pre-pickup cancel, driver-fault unassign with strike + ledger, post-pickup cancel rejection.

**Bug-class findings caught during build:**
- `User.phone` column is actually `phone_number` — many code touch-points required adjustment.
- `regions`/`service_areas` PostGIS column is `boundary`, not `polygon`.
- `OrderStatus` enum case is `DriverEnRoutePickup`/`DriverEnRouteDropoff` (with `Driver` prefix), not the shorter form initially planned.
- Project rate-limiter convention lives in `AppServiceProvider::configureRateLimiters()`, not `bootstrap/app.php`.
- Policy auto-discovery works in this project — no explicit `Gate::policy(...)` call needed.
- `OrderPolicy::act` requires `$user->hasRole('driver')`; sender-side abilities use FK ownership only (intentional asymmetry, documented in a class-level docblock).

### 17.9 Outstanding Architectural Questions (Future)

These questions are **not yet answered** but architectural decisions exist for them when needed:

1. **Storage Fee Policy** — flat daily? Tiered? Grace-period specifics for sub-project D
2. **Retrieval Payment Method at Office** — cash only? Wallet allowed for self-retrieval?
3. **Abandonment Disposition** — what happens to items after the 30-day threshold
4. **Admin Panel Scope** — which features admin needs from day one
5. **Rating & Review System** — when/how trust gets built
6. **SMS Provider Selection** — which Libyan provider to use
7. **Vehicle Type Expansion** — when/how to add van, truck, etc.
8. **Cancellation Fee Specifics** — exact LYD amounts for various scenarios (Slice 10 wired the knobs; values stay at 0 until business decides)

---

## 📌 Critical Design Principles (Always Apply)

These are the **non-negotiable rules** that guide all implementation decisions:

1. **Snapshot, don't recalculate** — financial data on orders is captured at creation, never recomputed
2. **Cash flow safety** — never pay out money the platform doesn't physically have
3. **Audit everything** — every status change, every transaction, every settlement is logged
4. **Atomic transactions** — financial operations wrapped in DB transactions, conditional updates for race conditions
5. **Public IDs, not internal IDs** — never expose auto-increment IDs in URLs/APIs
6. **Code-based handoffs** — physical item transfers always verified by codes
7. **Geofence + code fallbacks** — multiple verification paths for resilience
8. **Configurable, not hardcoded** — rates, timeouts, fees stored in `platform_settings`
9. **Build for v2, launch with MVP** — schema includes future fields, UI exposes current ones
10. **Hybrid receiver model** — never force receivers to register, but track them via phone

---

## 📞 Quick Reference: Key Numbers

| Setting | MVP Default | Configurable? |
|---|---|---|
| Driver assignment timeout | 10 min | Yes |
| Initial radius | 3 km | Yes |
| Radius tier 2 | 5 km @ 3 min, +20% surcharge | Yes |
| Radius tier 3 | 10 km @ 6 min, +50% surcharge | Yes |
| Item commission | 0% (architecture supports any rate) | Yes |
| Driver fee cut | 2% | Yes |
| Free storage period | 5 days | Yes |
| Abandonment threshold | 30 days | Yes |
| Pending → available delay | 48 hours | Yes |
| Min payout amount | 20 LYD | Yes |
| Strike threshold (review) | 3 in 30 days | Yes |
| Strike threshold (suspension) | 5 | Yes |
| New driver max liability | 100 LYD | Yes |
| Veteran driver max liability | 500 LYD (after 50 deliveries) | Yes |
| Driver location update (on order) | 10-15 seconds | Yes |
| Driver location update (idle) | 30-60 seconds | Yes |
| Pickup geofence tolerance | 500 m | Yes |
| GPS-lost auto-offline | 5 min | Yes |
| Inactivity auto-offline | 30 min | Yes |

---

### 17.10 Failed delivery + return-to-office milestone (2026-05-13) ✅

Built sub-project D per `docs/superpowers/specs/2026-05-13-failed-delivery-and-return-flow-design.md` and `docs/superpowers/plans/2026-05-13-failed-delivery-and-return-flow.md`.

**Endpoints shipped (8 routes):**

| Endpoint | Method | Auth |
|---|---|---|
| `/api/driver/orders/{public_id}/mark-delivery-failed` | POST | sanctum + role:driver + policy |
| `/api/office/orders` | GET | sanctum + role:office_staff |
| `/api/office/orders/{public_id}` | GET | sanctum + role:office_staff + office assignment |
| `/api/office/orders/{public_id}/receive-return` | POST | sanctum + role:office_staff + office assignment |
| `/api/office/orders/{public_id}/retrieve` | POST | sanctum + role:office_staff + office assignment |
| `/api/admin/orders/{public_id}/mark-delivery-failed` | POST | sanctum + role:admin |
| `/api/admin/orders/{public_id}/redirect-return` | POST | sanctum + role:admin |
| `/api/admin/orders/{public_id}/waive-retrieval-fees` | POST | sanctum + role:admin |

**Locked decisions:** driver/admin can fail post-pickup states; sender post-pickup cancellation remains rejected. Failed deliveries auto-chain to `returning_to_office`; office receipt moves to `at_office` and releases the driver. Driver earnings are credited at office receipt, except driver/platform-fault failures. Storage fees are computed just-in-time with configurable grace/daily/abandonment settings.

**Cross-cutting work:** added retrieval cash/waiver columns to `office_inventory`; added `FailedDeliveryService`, `ReturnOfficeResolver`, `StorageFeeCalculator`, office resources/controllers, D FormRequests, office rate limiters, and `AbandonStaleOrdersJob`. Extracted delivery-completion earnings credit into `DriverAccountLedgerService`. Added `Order::activeForDriver()` so returned-at-office orders no longer block driver availability.

**E2E smoke verified:** expanded `scripts/orders-e2e.php` to 17 rollback-wrapped scenarios covering happy path, C cancellation/strike paths, failed delivery, office receive, retrieval with storage, admin redirect, waiver, admin failure from `picked_up`, abandonment, and cash mismatch errors.

---

### 17.11 Settlement & Seller Payouts milestone (2026-05-17) ✅

Closes the cash loop end-to-end. Built per `docs/superpowers/specs/2026-05-17-settlement-and-seller-payouts-design.md` and `docs/superpowers/plans/2026-05-17-settlement-and-seller-payouts.md`. Implementation was split between Claude (services, controllers requiring multi-table locks, smoke, docs) and Codex (migrations, models, enums, FormRequests, JsonResources, read controllers, rate limiters, localization). 24 tasks total.

Office staff reconciles a driver's three buckets in one atomic transaction (all-or-nothing, excess rejected, disagreement leaves zero trace per §11.4). Sellers track per-order earnings through `pending_settlement → pending_clearance → available → paid_out` via the new `seller_earnings` table (1:1 with sale orders; the wide `orders` table stays untouched). 48h clearance window between settlement and seller-availability is configurable via `payouts.clearance_hours`. Sellers collect cash at any active office; identity verification at the counter is visual (staff judgement) — no payout codes. Admin reversal is permitted only while every contributing earning is still `pending_clearance` — standard correcting-settlement pattern (immutable original + reversed twin row).

**Endpoints shipped (15 routes):** 2 driver settlement-history, 3 seller (earnings + payout history), 6 office staff (3 settlement + 3 payout), 4 admin (3 settlement incl. reversal + 1 payout list).

**Schema deltas:** new `seller_earnings` table (1:1 unique on order_id; `seller_user_id` denormalized for hot-path queries; indexed on `(seller_user_id, status)` and `(status, cleared_at)`); new `seller_payout_orders` pivot; `seller_payouts` had request/approval columns dropped (`approved_at`, `approved_by_admin_id`, `rejected_at`, `rejected_by_admin_id`, `rejection_reason`, `requested_at`) and `paid_by_admin_id` renamed to `paid_by_staff_id`. Four new platform settings: `payouts.clearance_hours` (48), `payouts.min_amount` (20.00), `payouts.allow_partial` (true), `settlement.reverse_window_hours` (optional cap).

**Background job:** `ClearSellerEarningsJob`, scheduled daily as `seller-earnings.clearance`. Flips eligible `pending_clearance` earnings to `available`.

**Cross-cutting work:** extracted `User::isAssignedToOffice(int): bool` helper from `OrderPolicy::orderInUsersOffice` (now delegates); three new policies (`SettlementPolicy`, `SellerPayoutPolicy`, `SellerEarningPolicy`) with documented seller-side asymmetry (no Spatie `seller` role — FK ownership is the gate, mirroring `OrderPolicy::viewAsSender`); `CodeVerificationService` now spawns the seller-earning row on sale-order delivery success inline within the existing DB transaction.

**E2E smoke verified:** expanded `scripts/orders-e2e.php` to 31 rollback-wrapped scenarios — 14 new for settlement: happy match, empty/excess/shortage/zero-net settlements, sale-order earning state flips, clearance cron 48h cutoff (eligible + ineligible), payout happy path + partial + total mismatch + below minimum, reversal happy path + blocked-once-past-clearance.

### 17.12 Staff CRUD milestone (2026-05-20) ✅

Admin-mediated internal-account management. Built per `docs/superpowers/specs/2026-05-20-staff-crud-design.md`. **First milestone on the parallel-worktree workflow:** Claude built Slice A (core CRUD + auth middleware + policy) in `delivary-app-claude/`, Codex built Slice B (office assignments + e2e smoke) in `delivary-app-codex/`; Slice A merged first (PR #3), then Codex rebased and integrated Slice B (PR #4). Claude reviewed Slice B and caught one blocking assignment-lifecycle inversion (soft-removal mis-placed in `suspend()` instead of `deactivate()`), which Codex fixed before merge.

Admin creates an internal account (`admin` or `office_staff`); the system generates a random temporary password returned **once** in the response; the admin delivers it out-of-band; the employee is forced to change it on first use. The `EnsurePasswordChanged` middleware blocks any staff with `must_change_password=true` from every authenticated endpoint except the change-from-temp escape hatch and logout. Mirrors the face-to-face, no-self-registration trust model from driver onboarding. Lifecycle is soft only — never hard-delete (preserves FKs to `settlements.processed_by_staff_id`, `order_status_logs.actor_id`, etc.): **suspend** (revoke tokens, keep office assignments — reversible via reinstate), **reinstate**, **deactivate** (suspend + soft-remove all office assignments).

**Endpoints shipped (10 routes):** 8 under `/api/admin/staff` (index/show/store/update + suspend/reinstate/deactivate/reset-temp-password), 2 office-assignment routes nested in that group (`POST` + `DELETE .../office-assignments`), plus `POST /api/me/password/change-from-temp` (the only route, alongside logout, exempt from the password-change gate).

**Schema deltas:** `users.must_change_password` (boolean, default false); `office_staff_assignments` gained a `public_id` ULID (unique, route-key) and swapped its `unique(user_id, office_id)` constraint for a **partial unique index `WHERE removed_at IS NULL`** — so a previously-removed office can be re-assigned while duplicate *active* assignments stay blocked.

**Locked decisions:** Slice A rejected `role=office_staff` at the FormRequest layer (clean 422) during the merge window, widened to allow it in Slice B; the API never exposes a half-built endpoint. `StaffErrorCode` enum owned by Slice A, three Slice B cases (`ROLE_MISMATCH_FOR_OFFICE_ASSIGN` 422, `OFFICE_ASSIGNMENT_DUPLICATE` 409, `OFFICE_ASSIGNMENT_LAST_REQUIRED` 422) appended post-merge. `OfficeAssignmentService::attachMany()` runs inside `StaffService::create()`'s transaction (no nested transaction, skips role re-check); `detach()` uses `lockForUpdate` + a last-assignment guard (admin must `deactivate` to remove the final one). Temp passwords use `random_int()` over a 55-char ambiguity-free alphabet × 10 chars. No staff-action audit table this milestone (deferred to Account Moderation). Login gate (`AccountStatus::canLogin()`) blocks suspended/banned **after** password verification (anti-enumeration) and is enforced in `LoginService`.

**Security review (Claude, post-merge, per §10.8):** no HIGH/MEDIUM findings — double-gated authZ (route `role:admin` + `StaffPolicy`), token revocation on every state change, forced-password-change re-checked per request, IDOR-safe assignment detach (`abort_unless user_id === staff->id`), Eloquent-bound queries, explicit-array mass-assignment, `public_id`-only exposure.

**E2E smoke verified:** new `scripts/staff-e2e.php` (6 rollback-wrapped scenarios: forced-change, office_staff with 2 offices, reset-temp, suspend-preserves-assignments, reinstate-then-deactivate-removes, self-modify + last-admin guards). Full suite green on merged main: Pest 92/92, staff-e2e + orders-e2e (32/32) passing.

### 17.13 Real-time (Reverb) milestone (2026-05-18 → 2026-06-02) ✅

Replaces client polling with WebSocket push. Built per `docs/superpowers/specs/2026-05-18-realtime-reverb-design.md` in three phases: **Phase 1** (Claude — Reverb foundation, channel auth, broadcast-safe Resources, the two driver-pool events), **Phase 2** (per-event broadcasts across the order/driver/user surface), **Phase 3** (Claude — integration smoke + docs, this close-out). Backend-only: this milestone ships the API + event contracts; mobile/web clients subscribe later.

**Transport:** Laravel Reverb runs as a separate WS process (`reverb:start`, port 8080) talking to Laravel over Redis pub/sub. Business events are queued (`ShouldBroadcast`, `broadcasts` queue, `$afterCommit = true`); driver location is `ShouldBroadcastNow` and bypasses the queue (ephemeral — the next ping supersedes a lost one). The **driver app stays HTTP-only** — it keeps posting to `POST /api/driver/location`; the server fans that out. Cellular networks drop persistent sockets, so HTTP-up + WS-down is the deliberate design (industry standard for ride-hail/delivery).

**Channel auth:** Sanctum bearer token on `/broadcasting/auth` (Reverb never sees the token). Callbacks in `routes/channels.php`:
- `private:user.{userId}` — `$user->id === (int) $userId`
- `private:order.{orderPublicId}` — sender OR registered receiver, resolved by **`public_id`** (Critical Rule 11 — never internal id in channel names)
- `private:driver.{driverId}` — that driver only, `&& hasRole('driver')`
- `public:track.{trackingToken}` — public (26-char ULID, unguessable)

**Events catalog (9):** `OrderBroadcastToDriver` / `OrderBroadcastWithdrawn` (driver pool fan-out + withdrawal, Phase 1) on `private:driver.{id}`; `OrderStatusChanged` (private order) paired with `OrderStatusChangedPublic` (public tracking); `OrderDriverAssigned` (order + tracking); `OrderDriverLocationUpdated` (`ShouldBroadcastNow`, order + tracking); `DriverAccountUpdated` (`private:driver.{id}`); `NotificationReceived` (`private:user.{id}`); `SellerEarningCleared` (`private:user.{seller}`).

**Broadcast-safe Resources (critical pattern):** queued broadcasts run **off the HTTP request**, so actor-aware Resources (`OrderResource`, which branches on `$request->user()`) are unsafe — they'd serialise as if no user were present. Dedicated request-independent, audience-neutral Resources were created: `App\Http\Resources\Broadcast\OrderForPartiesResource` (order channel; excludes pickup notes, receiver phone/name, pickup/delivery codes, commission) and `DriverForOrderResource` (excludes internal id/user_id/office_id/plate). Existing `GuestTrackingResource` (public tracking) and `BroadcastOrderResource` (driver pool) were reused as already-safe.

**Dispatch sites:** `StateTransitionService` + `EscalationService` (broadcast-to-driver fan-out via `BroadcastService::eligibleDriversFor()`, the inverse of `candidatesFor()`); `ClaimService` + `AdminAssignmentService` (status + driver-assigned; admin assign/unassign also gained the previously-missing `OrderStatusChanged` dispatch); `BroadcastWithdrawnOnExit` listener (single source of truth — fires withdrawal to the remaining pool whenever an order leaves `awaiting_driver`); `PresenceService::updateLocation()` (location, dispatched *after* the transaction commits since `ShouldBroadcastNow` is not deferred by `$afterCommit`); `CodeVerificationService` / `DriverAccountLedgerService` / `SettlementService` / `SettlementReversalService` (driver account updates); `BroadcastDatabaseNotification` listener on `NotificationSent`; `ClearSellerEarningsJob` (per-row seller-earning cleared).

**Ops:** `composer dev` launches Reverb + the `broadcasts` queue worker alongside server/vite. `docs/deployment/reverb-supervisor.conf.example` documents the prod processes (reverb + `default` + `broadcasts` workers).

**Schema delta:** added the standard Laravel database-notifications table (`2026_06_03_000100_create_notifications_table` — uuid id, type, notifiable morph, data, read_at, timestamps). It was specced in §13.6 (In-App Notification Center) but never built: Laravel does not ship this migration by default, and §17.1's schema groups only enumerated business tables, so it fell through the gap until the realtime `NotificationReceived` path needed it. Now created, so database notifications persist and `NotificationReceived` is live end-to-end.

**Integration smoke verified:** new `scripts/realtime-smoke.php` swaps in an in-process recording broadcaster and drives a real order lifecycle end-to-end, asserting the exact broadcast sequence, channel names, and that each `broadcastWith()` payload serialises safely off-request (e.g. the order payload hides receiver phone/name/commission; the driver payload hides plate/internal ids). 6 scenarios incl. the live database-notification path; commits-then-cleans (no rollback harness — `$afterCommit` events only fire post-commit). Full suite green: **Pest 133/133, orders-e2e 32/32, realtime-smoke all scenarios.**

**Known gaps / deferred:**
- **Channel-name convention:** `private:user.{id}` and `private:driver.{id}` still authorise on **internal id** (as the realtime spec specified), whereas the id-exposure remediation (§10 of its spec) wanted them on `public_id` like the order channel. This holistic rename was deferred — it touches `routes/channels.php` plus every emission site (`new PrivateChannel('user.'.$id)` / `'driver.'.$id`) and is a candidate for a follow-up.
- Out of scope (per spec §7): push notifications (FCM/APNs), admin/office dashboard channel, presence channels, VoIP phone masking, TLS cert provisioning for `ws.delivary.ly`.

**End of Specification Document**

*This document represents all locked architectural decisions. Future questions and decisions should be appended to this document with date stamps.*
