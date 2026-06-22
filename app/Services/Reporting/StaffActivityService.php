<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates all staff/admin actions across the platform into a unified
 * chronological timeline, newest first.
 *
 * Each item is shaped as:
 *   ['kind' => string, 'occurred_at' => ISO8601, 'actor' => ['public_id' => ..., 'name' => ...], ...safeFields]
 *
 * SAFETY CONTRACT:
 *   - No internal integer IDs are exposed.
 *   - No raw phone numbers, emails, pickup codes, or delivery codes.
 *   - No order tracking tokens.
 *   - Setting values are omitted (may be sensitive).
 */
final class StaffActivityService
{
    /**
     * All supported source kinds, grouped by class for documentation.
     */
    private const KINDS_APPEND_ONLY = [
        'order_action',
        'settlement_processed',
        'seller_payout_paid',
        'account_moderation',
        'driver_account_adjustment',
        'driver_strike_issued',
        'driver_strike_voided',
        'office_return_received',
        'office_order_retrieved',
    ];

    private const KINDS_LATEST_POINTER = [
        'driver_approved',
        'driver_document_verified',
        'merchant_onboarded',
        'merchant_approved',
        'setting_updated',
        'order_abandoned',
    ];

    /**
     * Build the merged timeline for the given staff/admin user.
     *
     * @param  array<string>|null  $kinds  If null, all sources are queried.
     * @return array<int, array<string, mixed>> Flat array, newest-first.
     */
    public function timeline(User $staff, ?array $kinds = null): array
    {
        $allKinds = array_merge(self::KINDS_APPEND_ONLY, self::KINDS_LATEST_POINTER);
        $enabled = $kinds !== null ? array_intersect($kinds, $allKinds) : $allKinds;

        $actor = [
            'public_id' => $staff->public_id,
            'name' => $staff->fullName(),
        ];

        /** @var Collection<int, array<string, mixed>> $items */
        $items = collect();

        // ── Class (a): append-only sources ────────────────────────────────────

        if (in_array('order_action', $enabled, true)) {
            $items = $items->merge($this->orderActions($staff->id, $actor));
        }

        if (in_array('settlement_processed', $enabled, true)) {
            $items = $items->merge($this->settlementsProcessed($staff->id, $actor));
        }

        if (in_array('seller_payout_paid', $enabled, true)) {
            $items = $items->merge($this->sellerPayoutsPaid($staff->id, $actor));
        }

        if (in_array('account_moderation', $enabled, true)) {
            $items = $items->merge($this->accountModerations($staff->id, $actor));
        }

        if (in_array('driver_account_adjustment', $enabled, true)) {
            $items = $items->merge($this->driverAccountAdjustments($staff->id, $actor));
        }

        if (in_array('driver_strike_issued', $enabled, true)) {
            $items = $items->merge($this->driverStrikesIssued($staff->id, $actor));
        }

        if (in_array('driver_strike_voided', $enabled, true)) {
            $items = $items->merge($this->driverStrikesVoided($staff->id, $actor));
        }

        if (in_array('office_return_received', $enabled, true)) {
            $items = $items->merge($this->officeReturnsReceived($staff->id, $actor));
        }

        if (in_array('office_order_retrieved', $enabled, true)) {
            $items = $items->merge($this->officeOrdersRetrieved($staff->id, $actor));
        }

        // ── Class (b): latest-pointer sources ─────────────────────────────────

        if (in_array('driver_approved', $enabled, true)) {
            $items = $items->merge($this->driversApproved($staff->id, $actor));
        }

        if (in_array('driver_document_verified', $enabled, true)) {
            $items = $items->merge($this->driverDocumentsVerified($staff->id, $actor));
        }

        if (in_array('merchant_onboarded', $enabled, true)) {
            $items = $items->merge($this->merchantsOnboarded($staff->id, $actor));
        }

        if (in_array('merchant_approved', $enabled, true)) {
            $items = $items->merge($this->merchantsApproved($staff->id, $actor));
        }

        if (in_array('setting_updated', $enabled, true)) {
            $items = $items->merge($this->settingsUpdated($staff->id, $actor));
        }

        if (in_array('order_abandoned', $enabled, true)) {
            $items = $items->merge($this->ordersAbandoned($staff->id, $actor));
        }

        // ── Merge & sort newest first ─────────────────────────────────────────

        return $items
            ->sortByDesc('occurred_at')
            ->values()
            ->all();
    }

    // =========================================================================
    // Class (a) — Append-only event sources
    // =========================================================================

    /**
     * order_action: order_status_logs WHERE actor_type IN ('admin','office_staff')
     *
     * @param  array<string, string>  $actor
     * @return Collection<int, array<string, mixed>>
     */
    private function orderActions(int $staffId, array $actor): Collection
    {
        return DB::table('order_status_logs')
            ->join('orders', 'orders.id', '=', 'order_status_logs.order_id')
            ->where('order_status_logs.actor_id', $staffId)
            ->whereIn('order_status_logs.actor_type', ['admin', 'office_staff'])
            ->whereNull('orders.deleted_at')
            ->orderBy('order_status_logs.created_at', 'desc')
            ->select([
                'orders.public_id       AS order_public_id',
                'order_status_logs.from_status',
                'order_status_logs.to_status',
                'order_status_logs.created_at AS occurred_at',
            ])
            ->get()
            ->map(fn (object $row): array => [
                'kind' => 'order_action',
                'occurred_at' => $this->toIso($row->occurred_at),
                'actor' => $actor,
                'order' => ['public_id' => $row->order_public_id],
                'from_status' => $row->from_status,
                'to_status' => $row->to_status,
            ]);
    }

    /**
     * settlement_processed: settlements.processed_by_staff_id
     *
     * @param  array<string, string>  $actor
     * @return Collection<int, array<string, mixed>>
     */
    private function settlementsProcessed(int $staffId, array $actor): Collection
    {
        return DB::table('settlements')
            ->join('users AS driver', 'driver.id', '=', 'settlements.driver_id')
            ->where('settlements.processed_by_staff_id', $staffId)
            ->whereNull('settlements.deleted_at')
            ->orderBy('settlements.created_at', 'desc')
            ->selectRaw(
                'settlements.public_id                  AS settlement_public_id,
                 driver.public_id                       AS driver_public_id,
                 CONCAT(driver.first_name, \' \', driver.last_name) AS driver_name,
                 settlements.cash_received_from_driver,
                 settlements.cash_paid_to_driver,
                 settlements.created_at                 AS occurred_at'
            )
            ->get()
            ->map(fn (object $row): array => [
                'kind' => 'settlement_processed',
                'occurred_at' => $this->toIso($row->occurred_at),
                'actor' => $actor,
                'settlement' => ['public_id' => $row->settlement_public_id],
                'driver' => [
                    'public_id' => $row->driver_public_id,
                    'name' => $row->driver_name,
                ],
                'cash_received_from_driver' => $row->cash_received_from_driver,
                'cash_paid_to_driver' => $row->cash_paid_to_driver,
            ]);
    }

    /**
     * seller_payout_paid: seller_payouts.paid_by_staff_id
     *
     * @param  array<string, string>  $actor
     * @return Collection<int, array<string, mixed>>
     */
    private function sellerPayoutsPaid(int $staffId, array $actor): Collection
    {
        return DB::table('seller_payouts')
            ->join('users AS seller', 'seller.id', '=', 'seller_payouts.user_id')
            ->where('seller_payouts.paid_by_staff_id', $staffId)
            ->whereNull('seller_payouts.deleted_at')
            ->whereNotNull('seller_payouts.paid_at')
            ->orderBy('seller_payouts.paid_at', 'desc')
            ->selectRaw(
                'seller_payouts.public_id                       AS payout_public_id,
                 seller.public_id                               AS seller_public_id,
                 CONCAT(seller.first_name, \' \', seller.last_name) AS seller_name,
                 seller_payouts.amount,
                 seller_payouts.paid_at                         AS occurred_at'
            )
            ->get()
            ->map(fn (object $row): array => [
                'kind' => 'seller_payout_paid',
                'occurred_at' => $this->toIso($row->occurred_at),
                'actor' => $actor,
                'payout' => ['public_id' => $row->payout_public_id],
                'seller' => [
                    'public_id' => $row->seller_public_id,
                    'name' => $row->seller_name,
                ],
                'amount' => $row->amount,
            ]);
    }

    /**
     * account_moderation: account_moderation_actions.actor_id
     *
     * @param  array<string, string>  $actor
     * @return Collection<int, array<string, mixed>>
     */
    private function accountModerations(int $staffId, array $actor): Collection
    {
        return DB::table('account_moderation_actions')
            ->join('users AS target', 'target.id', '=', 'account_moderation_actions.user_id')
            ->where('account_moderation_actions.actor_id', $staffId)
            ->orderBy('account_moderation_actions.created_at', 'desc')
            ->selectRaw(
                'target.public_id                                   AS target_public_id,
                 CONCAT(target.first_name, \' \', target.last_name) AS target_name,
                 account_moderation_actions.action,
                 account_moderation_actions.reason_code,
                 account_moderation_actions.created_at              AS occurred_at'
            )
            ->get()
            ->map(fn (object $row): array => [
                'kind' => 'account_moderation',
                'occurred_at' => $this->toIso($row->occurred_at),
                'actor' => $actor,
                'target' => [
                    'public_id' => $row->target_public_id,
                    'name' => $row->target_name,
                ],
                'action' => $row->action,
                'reason_code' => $row->reason_code,
            ]);
    }

    /**
     * driver_account_adjustment: driver_account_transactions.created_by_admin_id
     *
     * @param  array<string, string>  $actor
     * @return Collection<int, array<string, mixed>>
     */
    private function driverAccountAdjustments(int $staffId, array $actor): Collection
    {
        return DB::table('driver_account_transactions')
            ->join('users AS driver', 'driver.id', '=', 'driver_account_transactions.driver_id')
            ->where('driver_account_transactions.created_by_admin_id', $staffId)
            ->orderBy('driver_account_transactions.created_at', 'desc')
            ->selectRaw(
                'driver.public_id                                    AS driver_public_id,
                 CONCAT(driver.first_name, \' \', driver.last_name) AS driver_name,
                 driver_account_transactions.bucket,
                 driver_account_transactions.amount,
                 driver_account_transactions.reason,
                 driver_account_transactions.created_at              AS occurred_at'
            )
            ->get()
            ->map(fn (object $row): array => [
                'kind' => 'driver_account_adjustment',
                'occurred_at' => $this->toIso($row->occurred_at),
                'actor' => $actor,
                'driver' => [
                    'public_id' => $row->driver_public_id,
                    'name' => $row->driver_name,
                ],
                'bucket' => $row->bucket,
                'amount' => $row->amount,
                'reason' => $row->reason,
            ]);
    }

    /**
     * driver_strike_issued: driver_strikes.issued_by_admin_id
     *
     * @param  array<string, string>  $actor
     * @return Collection<int, array<string, mixed>>
     */
    private function driverStrikesIssued(int $staffId, array $actor): Collection
    {
        return DB::table('driver_strikes')
            ->join('users AS driver', 'driver.id', '=', 'driver_strikes.driver_id')
            ->where('driver_strikes.issued_by_admin_id', $staffId)
            ->orderBy('driver_strikes.created_at', 'desc')
            ->selectRaw(
                'driver.public_id                                    AS driver_public_id,
                 CONCAT(driver.first_name, \' \', driver.last_name) AS driver_name,
                 driver_strikes.reason,
                 driver_strikes.fee_amount,
                 driver_strikes.created_at                           AS occurred_at'
            )
            ->get()
            ->map(fn (object $row): array => [
                'kind' => 'driver_strike_issued',
                'occurred_at' => $this->toIso($row->occurred_at),
                'actor' => $actor,
                'driver' => [
                    'public_id' => $row->driver_public_id,
                    'name' => $row->driver_name,
                ],
                'reason' => $row->reason,
                'fee_amount' => $row->fee_amount,
            ]);
    }

    /**
     * driver_strike_voided: driver_strikes.voided_by_admin_id
     * occurred_at = voided_at
     *
     * @param  array<string, string>  $actor
     * @return Collection<int, array<string, mixed>>
     */
    private function driverStrikesVoided(int $staffId, array $actor): Collection
    {
        return DB::table('driver_strikes')
            ->join('users AS driver', 'driver.id', '=', 'driver_strikes.driver_id')
            ->where('driver_strikes.voided_by_admin_id', $staffId)
            ->whereNotNull('driver_strikes.voided_at')
            ->orderBy('driver_strikes.voided_at', 'desc')
            ->selectRaw(
                'driver_strikes.public_id                            AS strike_public_id,
                 driver.public_id                                    AS driver_public_id,
                 CONCAT(driver.first_name, \' \', driver.last_name) AS driver_name,
                 driver_strikes.void_reason,
                 driver_strikes.voided_at                            AS occurred_at'
            )
            ->get()
            ->map(fn (object $row): array => [
                'kind' => 'driver_strike_voided',
                'occurred_at' => $this->toIso($row->occurred_at),
                'actor' => $actor,
                'strike' => ['public_id' => $row->strike_public_id],
                'driver' => [
                    'public_id' => $row->driver_public_id,
                    'name' => $row->driver_name,
                ],
                'void_reason' => $row->void_reason,
            ]);
    }

    /**
     * office_return_received: office_inventory.received_by_staff_id
     * occurred_at = received_at
     *
     * @param  array<string, string>  $actor
     * @return Collection<int, array<string, mixed>>
     */
    private function officeReturnsReceived(int $staffId, array $actor): Collection
    {
        return DB::table('office_inventory')
            ->join('orders', 'orders.id', '=', 'office_inventory.order_id')
            ->where('office_inventory.received_by_staff_id', $staffId)
            ->whereNotNull('office_inventory.received_at')
            ->whereNull('office_inventory.deleted_at')
            ->orderBy('office_inventory.received_at', 'desc')
            ->select([
                'orders.public_id        AS order_public_id',
                'office_inventory.received_at AS occurred_at',
            ])
            ->get()
            ->map(fn (object $row): array => [
                'kind' => 'office_return_received',
                'occurred_at' => $this->toIso($row->occurred_at),
                'actor' => $actor,
                'order' => ['public_id' => $row->order_public_id],
            ]);
    }

    /**
     * office_order_retrieved: office_inventory.retrieved_by_staff_id
     * occurred_at = retrieved_at
     *
     * @param  array<string, string>  $actor
     * @return Collection<int, array<string, mixed>>
     */
    private function officeOrdersRetrieved(int $staffId, array $actor): Collection
    {
        return DB::table('office_inventory')
            ->join('orders', 'orders.id', '=', 'office_inventory.order_id')
            ->where('office_inventory.retrieved_by_staff_id', $staffId)
            ->whereNull('office_inventory.deleted_at')
            ->whereNotNull('office_inventory.retrieved_at')
            ->orderBy('office_inventory.retrieved_at', 'desc')
            ->select([
                'orders.public_id              AS order_public_id',
                'office_inventory.retrieved_at AS occurred_at',
            ])
            ->get()
            ->map(fn (object $row): array => [
                'kind' => 'office_order_retrieved',
                'occurred_at' => $this->toIso($row->occurred_at),
                'actor' => $actor,
                'order' => ['public_id' => $row->order_public_id],
            ]);
    }

    // =========================================================================
    // Class (b) — Latest-pointer attribution sources
    // =========================================================================

    /**
     * driver_approved: driver_profiles.approved_by_admin_id
     * occurred_at = approved_at (skip if null)
     *
     * @param  array<string, string>  $actor
     * @return Collection<int, array<string, mixed>>
     */
    private function driversApproved(int $staffId, array $actor): Collection
    {
        return DB::table('driver_profiles')
            ->join('users AS driver', 'driver.id', '=', 'driver_profiles.user_id')
            ->where('driver_profiles.approved_by_admin_id', $staffId)
            ->whereNotNull('driver_profiles.approved_at')
            ->whereNull('driver_profiles.deleted_at')
            ->orderBy('driver_profiles.approved_at', 'desc')
            ->selectRaw(
                'driver.public_id                                    AS driver_public_id,
                 CONCAT(driver.first_name, \' \', driver.last_name) AS driver_name,
                 driver_profiles.approved_at                         AS occurred_at'
            )
            ->get()
            ->map(fn (object $row): array => [
                'kind' => 'driver_approved',
                'occurred_at' => $this->toIso($row->occurred_at),
                'actor' => $actor,
                'driver' => [
                    'public_id' => $row->driver_public_id,
                    'name' => $row->driver_name,
                ],
            ]);
    }

    /**
     * driver_document_verified: driver_documents.verified_by_admin_id
     * occurred_at = verified_at if present, else updated_at
     *
     * @param  array<string, string>  $actor
     * @return Collection<int, array<string, mixed>>
     */
    private function driverDocumentsVerified(int $staffId, array $actor): Collection
    {
        return DB::table('driver_documents')
            ->join('users AS driver', 'driver.id', '=', 'driver_documents.driver_id')
            ->where('driver_documents.verified_by_admin_id', $staffId)
            ->orderByRaw('COALESCE(driver_documents.verified_at, driver_documents.updated_at) DESC')
            ->selectRaw(
                'driver.public_id                                    AS driver_public_id,
                 CONCAT(driver.first_name, \' \', driver.last_name) AS driver_name,
                 driver_documents.document_type,
                 COALESCE(driver_documents.verified_at, driver_documents.updated_at) AS occurred_at'
            )
            ->get()
            ->map(fn (object $row): array => [
                'kind' => 'driver_document_verified',
                'occurred_at' => $this->toIso($row->occurred_at),
                'actor' => $actor,
                'driver' => [
                    'public_id' => $row->driver_public_id,
                    'name' => $row->driver_name,
                ],
                'document_type' => $row->document_type,
            ]);
    }

    /**
     * merchant_onboarded: merchant_profiles.created_by_admin_id
     * occurred_at = created_at
     *
     * @param  array<string, string>  $actor
     * @return Collection<int, array<string, mixed>>
     */
    private function merchantsOnboarded(int $staffId, array $actor): Collection
    {
        return DB::table('merchant_profiles')
            ->where('merchant_profiles.created_by_admin_id', $staffId)
            ->whereNull('merchant_profiles.deleted_at')
            ->orderBy('merchant_profiles.created_at', 'desc')
            ->select([
                'merchant_profiles.public_id      AS merchant_public_id',
                'merchant_profiles.business_name  AS merchant_name',
                'merchant_profiles.created_at     AS occurred_at',
            ])
            ->get()
            ->map(fn (object $row): array => [
                'kind' => 'merchant_onboarded',
                'occurred_at' => $this->toIso($row->occurred_at),
                'actor' => $actor,
                'merchant' => [
                    'public_id' => $row->merchant_public_id,
                    'name' => $row->merchant_name,
                ],
            ]);
    }

    /**
     * merchant_approved: merchant_profiles.approved_by_admin_id
     * occurred_at = approved_at (skip if null)
     *
     * @param  array<string, string>  $actor
     * @return Collection<int, array<string, mixed>>
     */
    private function merchantsApproved(int $staffId, array $actor): Collection
    {
        return DB::table('merchant_profiles')
            ->where('merchant_profiles.approved_by_admin_id', $staffId)
            ->whereNotNull('merchant_profiles.approved_at')
            ->whereNull('merchant_profiles.deleted_at')
            ->orderBy('merchant_profiles.approved_at', 'desc')
            ->select([
                'merchant_profiles.public_id      AS merchant_public_id',
                'merchant_profiles.business_name  AS merchant_name',
                'merchant_profiles.approved_at    AS occurred_at',
            ])
            ->get()
            ->map(fn (object $row): array => [
                'kind' => 'merchant_approved',
                'occurred_at' => $this->toIso($row->occurred_at),
                'actor' => $actor,
                'merchant' => [
                    'public_id' => $row->merchant_public_id,
                    'name' => $row->merchant_name,
                ],
            ]);
    }

    /**
     * setting_updated: platform_settings.updated_by_admin_id
     * occurred_at = updated_at
     * CRITICAL: value is intentionally omitted — may be sensitive.
     *
     * @param  array<string, string>  $actor
     * @return Collection<int, array<string, mixed>>
     */
    private function settingsUpdated(int $staffId, array $actor): Collection
    {
        return DB::table('platform_settings')
            ->where('platform_settings.updated_by_admin_id', $staffId)
            ->orderBy('platform_settings.updated_at', 'desc')
            ->select([
                'platform_settings.key',
                'platform_settings.updated_at AS occurred_at',
            ])
            ->get()
            ->map(fn (object $row): array => [
                'kind' => 'setting_updated',
                'occurred_at' => $this->toIso($row->occurred_at),
                'actor' => $actor,
                'key' => $row->key,
                // value intentionally omitted
            ]);
    }

    /**
     * order_abandoned: office_inventory.abandoned_by_admin_id
     * occurred_at = abandoned_at (skip if null)
     *
     * @param  array<string, string>  $actor
     * @return Collection<int, array<string, mixed>>
     */
    private function ordersAbandoned(int $staffId, array $actor): Collection
    {
        return DB::table('office_inventory')
            ->join('orders', 'orders.id', '=', 'office_inventory.order_id')
            ->where('office_inventory.abandoned_by_admin_id', $staffId)
            ->whereNull('office_inventory.deleted_at')
            ->whereNotNull('office_inventory.abandoned_at')
            ->orderBy('office_inventory.abandoned_at', 'desc')
            ->select([
                'orders.public_id              AS order_public_id',
                'office_inventory.abandoned_at AS occurred_at',
            ])
            ->get()
            ->map(fn (object $row): array => [
                'kind' => 'order_abandoned',
                'occurred_at' => $this->toIso($row->occurred_at),
                'actor' => $actor,
                'order' => ['public_id' => $row->order_public_id],
            ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Normalise a DB timestamp string to ISO 8601 with timezone offset.
     * Postgres returns timestamps without timezone as plain strings;
     * we parse and re-format through Carbon so the format is always consistent.
     */
    private function toIso(string $timestamp): string
    {
        return Carbon::parse($timestamp)->toIso8601String();
    }
}
