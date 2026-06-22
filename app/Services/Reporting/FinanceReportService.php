<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\SellerPayoutStatus;
use App\Enums\SettlementStatus;
use App\Support\ReportingTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class FinanceReportService
{
    public function __construct(private readonly ReportingTime $time) {}

    /**
     * Build the full finance report for the given range and optional office filter.
     *
     * @return array{
     *     accrued: array{commission: string, fee_cut: string, total: string},
     *     cash: array{settlement_cash_net: string, payouts: string, total: string},
     *     gap: string,
     *     by_source: array<int, array{key: string, amount: string}>,
     *     by_merchant: array<int, array{merchant: array{public_id: string, name: string}, amount: string}>,
     *     by_office: array<int, array{office_id: string|int, amount: string}>,
     *     daily_trend: array<int, array{date: string, amount: string}>,
     *     recent_orders: array<int, array{order_public_id: string, type: string, merchant: array{public_id: string, name: string}|null, item_value: string|null, commission_amount: string, driver_fee_cut_amount: string, platform_revenue: string}>
     * }
     */
    public function build(string $range, ?int $officeId): array
    {
        [$from, $to] = $this->time->rangeBounds($range);

        $accrued = $this->accrued($from, $to, $officeId);
        $cash = $this->cash($from, $to, $officeId);

        return [
            'accrued' => $accrued,
            'cash' => $cash,
            'gap' => bcsub($accrued['total'], $cash['total'], 2),
            'by_source' => [
                ['key' => 'commission', 'amount' => $accrued['commission']],
                ['key' => 'fee_cut',    'amount' => $accrued['fee_cut']],
            ],
            'by_merchant' => $this->byMerchant($from, $to, $officeId),
            'by_office' => $this->byOffice($from, $to, $officeId),
            'daily_trend' => $this->dailyTrend($from, $to, $officeId),
            'recent_orders' => $this->recentOrders($from, $to, $officeId),
        ];
    }

    // -------------------------------------------------------------------------
    // Private aggregates
    // -------------------------------------------------------------------------

    /**
     * @return array{commission: string, fee_cut: string, total: string}
     */
    private function accrued(CarbonImmutable $from, CarbonImmutable $to, ?int $officeId): array
    {
        $query = DB::table('orders')
            ->where('orders.status', OrderStatus::Delivered->value)
            ->where('orders.delivered_at', '>=', $from)
            ->where('orders.delivered_at', '<', $to)
            ->selectRaw(
                'COALESCE(SUM(commission_amount), 0)::numeric(14,2)::text     AS commission,
                 COALESCE(SUM(driver_fee_cut_amount), 0)::numeric(14,2)::text AS fee_cut'
            );

        if ($officeId !== null) {
            $query->whereRaw($this->spatialOfficeSubquery(), [$officeId]);
        }

        $row = $query->first();

        $commission = (string) ($row->commission ?? '0.00');
        $feeCut = (string) ($row->fee_cut ?? '0.00');

        return [
            'commission' => $commission,
            'fee_cut' => $feeCut,
            'total' => bcadd($commission, $feeCut, 2),
        ];
    }

    /**
     * @return array{settlement_cash_net: string, payouts: string, total: string}
     */
    private function cash(CarbonImmutable $from, CarbonImmutable $to, ?int $officeId): array
    {
        // Settlement net: Σ(cash_received_from_driver − cash_paid_to_driver)
        // Bucket on settlements.created_at, filter status='completed'.
        $settlementQuery = DB::table('settlements')
            ->whereNull('settlements.deleted_at')
            ->where('settlements.status', SettlementStatus::Completed->value)
            ->where('settlements.created_at', '>=', $from)
            ->where('settlements.created_at', '<', $to)
            ->selectRaw(
                'COALESCE(SUM(cash_received_from_driver - cash_paid_to_driver), 0)::numeric(14,2)::text AS net'
            );

        if ($officeId !== null) {
            $settlementQuery->where('settlements.office_id', $officeId);
        }

        $netRow = $settlementQuery->first();
        $net = (string) ($netRow->net ?? '0.00');

        // Seller payouts: Σ amount WHERE status='paid', bucket on paid_at.
        $payoutsQuery = DB::table('seller_payouts')
            ->whereNull('seller_payouts.deleted_at')
            ->where('seller_payouts.status', SellerPayoutStatus::Paid->value)
            ->where('seller_payouts.paid_at', '>=', $from)
            ->where('seller_payouts.paid_at', '<', $to)
            ->selectRaw(
                'COALESCE(SUM(amount), 0)::numeric(14,2)::text AS payouts'
            );

        if ($officeId !== null) {
            $payoutsQuery->where('seller_payouts.office_id', $officeId);
        }

        $payoutsRow = $payoutsQuery->first();
        $payouts = (string) ($payoutsRow->payouts ?? '0.00');

        return [
            'settlement_cash_net' => $net,
            'payouts' => $payouts,
            'total' => bcsub($net, $payouts, 2),
        ];
    }

    /**
     * Top 6 merchant_delivery orders grouped by merchant, sorted by descending platform revenue.
     *
     * @return array<int, array{merchant: array{public_id: string, name: string}, amount: string}>
     */
    private function byMerchant(CarbonImmutable $from, CarbonImmutable $to, ?int $officeId): array
    {
        $query = DB::table('orders')
            ->join('merchant_profiles', 'merchant_profiles.id', '=', 'orders.merchant_profile_id')
            ->whereNull('orders.deleted_at')
            ->whereNull('merchant_profiles.deleted_at')
            ->where('orders.status', OrderStatus::Delivered->value)
            ->where('orders.order_type', OrderType::MerchantDelivery->value)
            ->where('orders.delivered_at', '>=', $from)
            ->where('orders.delivered_at', '<', $to)
            ->groupBy('merchant_profiles.id', 'merchant_profiles.public_id', 'merchant_profiles.business_name')
            ->orderByRaw('SUM(orders.commission_amount + orders.driver_fee_cut_amount) DESC')
            ->limit(6)
            ->selectRaw(
                'merchant_profiles.public_id                                                       AS merchant_public_id,
                 merchant_profiles.business_name                                                   AS merchant_name,
                 COALESCE(SUM(orders.commission_amount + orders.driver_fee_cut_amount), 0)::numeric(14,2)::text AS amount'
            );

        if ($officeId !== null) {
            $query->whereRaw($this->spatialOfficeSubquery(), [$officeId]);
        }

        return $query
            ->get()
            ->map(fn (object $row): array => [
                'merchant' => [
                    'public_id' => $row->merchant_public_id,
                    'name' => $row->merchant_name,
                ],
                'amount' => $row->amount,
            ])
            ->values()
            ->all();
    }

    /**
     * Platform revenue grouped by the office whose region contains the pickup location.
     * Pickup not in any active region → 'unassigned'.
     *
     * @return array<int, array{office_id: string|int, amount: string}>
     */
    private function byOffice(CarbonImmutable $from, CarbonImmutable $to, ?int $officeId): array
    {
        // Build a subquery that resolves the office_id for each order from the
        // spatial join (same logic as PricingService::resolveRegion).
        $query = DB::table('orders')
            ->whereNull('orders.deleted_at')
            ->where('orders.status', OrderStatus::Delivered->value)
            ->where('orders.delivered_at', '>=', $from)
            ->where('orders.delivered_at', '<', $to)
            ->leftJoin('regions', function ($join): void {
                $join->whereRaw('regions.is_active = true')
                    ->whereRaw(
                        'ST_Contains(regions.boundary::geometry, orders.pickup_location::geometry)'
                    );
            })
            ->leftJoin('service_areas', function ($join): void {
                $join->on('service_areas.id', '=', 'regions.service_area_id')
                    ->whereRaw('service_areas.is_active = true');
            })
            ->groupByRaw('regions.office_id')
            ->selectRaw(
                'regions.office_id,
                 COALESCE(SUM(orders.commission_amount + orders.driver_fee_cut_amount), 0)::numeric(14,2)::text AS amount'
            );

        if ($officeId !== null) {
            $query->where('regions.office_id', $officeId);
        }

        return $query
            ->get()
            ->map(fn (object $row): array => [
                'office_id' => $row->office_id ?? 'unassigned',
                'amount' => $row->amount,
            ])
            ->values()
            ->all();
    }

    /**
     * Daily platform revenue bucketed on the local-tz date of delivered_at.
     *
     * @return array<int, array{date: string, amount: string}>
     */
    private function dailyTrend(CarbonImmutable $from, CarbonImmutable $to, ?int $officeId): array
    {
        $dateExpr = $this->time->sqlLocalDate('orders.delivered_at');

        $query = DB::table('orders')
            ->whereNull('orders.deleted_at')
            ->where('orders.status', OrderStatus::Delivered->value)
            ->where('orders.delivered_at', '>=', $from)
            ->where('orders.delivered_at', '<', $to)
            ->groupByRaw($dateExpr)
            ->orderByRaw("{$dateExpr} ASC")
            ->selectRaw(
                "{$dateExpr} AS local_date,
                 COALESCE(SUM(orders.commission_amount + orders.driver_fee_cut_amount), 0)::numeric(14,2)::text AS amount"
            );

        if ($officeId !== null) {
            $query->whereRaw($this->spatialOfficeSubquery(), [$officeId]);
        }

        return $query
            ->get()
            ->map(fn (object $row): array => [
                'date' => $row->local_date,
                'amount' => $row->amount,
            ])
            ->values()
            ->all();
    }

    /**
     * Latest ~12 delivered orders in the range, sorted by delivered_at DESC.
     *
     * @return array<int, array{order_public_id: string, type: string, merchant: array{public_id: string, name: string}|null, item_value: string|null, commission_amount: string, driver_fee_cut_amount: string, platform_revenue: string}>
     */
    private function recentOrders(CarbonImmutable $from, CarbonImmutable $to, ?int $officeId): array
    {
        $query = DB::table('orders')
            ->leftJoin('merchant_profiles', 'merchant_profiles.id', '=', 'orders.merchant_profile_id')
            ->whereNull('orders.deleted_at')
            ->where('orders.status', OrderStatus::Delivered->value)
            ->where('orders.delivered_at', '>=', $from)
            ->where('orders.delivered_at', '<', $to)
            ->orderBy('orders.delivered_at', 'desc')
            ->orderBy('orders.id', 'desc')
            ->limit(12)
            ->selectRaw(
                'orders.public_id                                                                                           AS order_public_id,
                 orders.order_type                                                                                          AS type,
                 merchant_profiles.public_id                                                                                AS merchant_public_id,
                 merchant_profiles.business_name                                                                            AS merchant_name,
                 orders.item_value::numeric(14,2)::text                                                                     AS item_value,
                 orders.commission_amount::numeric(14,2)::text                                                              AS commission_amount,
                 orders.driver_fee_cut_amount::numeric(14,2)::text                                                          AS driver_fee_cut_amount,
                 (orders.commission_amount + orders.driver_fee_cut_amount)::numeric(14,2)::text                             AS platform_revenue'
            );

        if ($officeId !== null) {
            $query->whereRaw($this->spatialOfficeSubquery(), [$officeId]);
        }

        return $query
            ->get()
            ->map(fn (object $row): array => [
                'order_public_id' => $row->order_public_id,
                'type' => $row->type,
                'merchant' => $row->merchant_public_id !== null
                    ? ['public_id' => $row->merchant_public_id, 'name' => $row->merchant_name]
                    : null,
                'item_value' => $row->item_value,
                'commission_amount' => $row->commission_amount,
                'driver_fee_cut_amount' => $row->driver_fee_cut_amount,
                'platform_revenue' => $row->platform_revenue,
            ])
            ->values()
            ->all();
    }

    // -------------------------------------------------------------------------
    // SQL helpers
    // -------------------------------------------------------------------------

    /**
     * Returns a parameterised SQL fragment (one `?` placeholder = office_id)
     * that is true when an order's pickup_location falls inside an active region
     * owned by the given office — mirrors PricingService::resolveRegion().
     */
    private function spatialOfficeSubquery(): string
    {
        return 'EXISTS (
            SELECT 1
            FROM regions
            JOIN service_areas ON service_areas.id = regions.service_area_id
            WHERE regions.is_active = true
              AND service_areas.is_active = true
              AND regions.office_id = ?
              AND ST_Contains(regions.boundary::geometry, orders.pickup_location::geometry)
        )';
    }
}
