<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Enums\DriverActivityStatus;
use App\Enums\OrderStatus;
use App\Models\DriverAccount;
use App\Models\DriverProfile;
use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Models\User;
use App\Support\ReportingTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class OverviewMetricsService
{
    public function __construct(private readonly ReportingTime $time) {}

    /** @return array{stats: array<int, array<string, mixed>>, activity: array<int, array<string, mixed>>} */
    public function build(): array
    {
        return [
            'stats' => [
                $this->deliveredTodayStat(),
                $this->gauge('active_orders', $this->activeOrders()),
                $this->gauge('online_drivers', $this->onlineDrivers()),
                $this->gauge('pending_settlements', $this->pendingSettlements()),
            ],
            'activity' => $this->recentActivity(),
        ];
    }

    /** @return array{id: string, value: int, money: bool, delta_pct: float|int|null, direction: string|null, sparkline: array<int, int>|null} */
    private function deliveredTodayStat(): array
    {
        [$todayFrom, $todayTo] = $this->localDayBounds(0, true);
        [$previousFrom, $previousTo] = $this->localDayBounds(1, false);

        $today = $this->deliveredCount($todayFrom, $todayTo);
        $previous = $this->deliveredCount($previousFrom, $previousTo);

        return [
            'id' => 'delivered_today',
            'value' => $today,
            'money' => false,
            'delta_pct' => $this->deltaPct($today, $previous),
            'direction' => $this->direction($today, $previous),
            'sparkline' => $this->deliveredSparkline(),
        ];
    }

    /** @return array{id: string, value: int, money: bool, delta_pct: null, direction: null, sparkline: null} */
    private function gauge(string $id, int $value): array
    {
        return [
            'id' => $id,
            'value' => $value,
            'money' => false,
            'delta_pct' => null,
            'direction' => null,
            'sparkline' => null,
        ];
    }

    private function activeOrders(): int
    {
        return Order::query()->active()->count();
    }

    private function onlineDrivers(): int
    {
        return DriverProfile::query()
            ->where('activity_status', '<>', DriverActivityStatus::Offline->value)
            ->count();
    }

    private function pendingSettlements(): int
    {
        return DriverAccount::query()
            ->where(static fn ($query) => $query
                ->where('cash_to_deposit', '<>', '0.00')
                ->orWhere('earnings_balance', '<>', '0.00')
                ->orWhere('debt_balance', '<>', '0.00'))
            ->count();
    }

    private function deliveredCount(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return Order::query()
            ->where('status', OrderStatus::Delivered->value)
            ->where('delivered_at', '>=', $from)
            ->where('delivered_at', '<', $to)
            ->count();
    }

    /** @return array<int, int> */
    private function deliveredSparkline(): array
    {
        $tz = $this->time->timezone();
        $now = CarbonImmutable::now($tz);
        $startLocal = $now->startOfDay()->subDays(6);
        $dateExpr = $this->time->sqlLocalDate('orders.delivered_at');

        $rows = DB::table('orders')
            ->where('orders.status', OrderStatus::Delivered->value)
            ->where('orders.delivered_at', '>=', $startLocal->setTimezone('UTC'))
            ->where('orders.delivered_at', '<', $now->setTimezone('UTC'))
            ->selectRaw("{$dateExpr} AS local_date, COUNT(*)::int AS total")
            ->groupByRaw($dateExpr)
            ->pluck('total', 'local_date');

        $counts = [];

        for ($i = 0; $i < 7; $i++) {
            $date = $startLocal->addDays($i)->format('Y-m-d');
            $counts[] = (int) ($rows[$date] ?? 0);
        }

        return $counts;
    }

    /**
     * @return array<int, array{
     *     kind: string,
     *     order_public_id: string|null,
     *     actor: array{public_id: string, name: string}|null,
     *     to_status: string,
     *     created_at: string|null
     * }>
     */
    private function recentActivity(): array
    {
        return OrderStatusLog::query()
            ->with([
                'order:id,public_id',
                'actor:id,public_id,first_name,last_name',
            ])
            ->latest('created_at')
            ->latest('id')
            ->limit(15)
            ->get()
            ->map(fn (OrderStatusLog $log): array => [
                'kind' => $this->kindFor($log->to_status),
                'order_public_id' => $log->order?->public_id,
                'actor' => $this->actor($log->actor),
                'to_status' => $log->to_status->value,
                'created_at' => $log->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /** @return array{public_id: string, name: string}|null */
    private function actor(?User $actor): ?array
    {
        if ($actor === null) {
            return null;
        }

        return [
            'public_id' => $actor->public_id,
            'name' => $actor->fullName(),
        ];
    }

    private function kindFor(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::Delivered => 'delivered',
            OrderStatus::Assigned => 'assigned',
            OrderStatus::DeliveryFailed,
            OrderStatus::ReturningToOffice,
            OrderStatus::AtOffice,
            OrderStatus::RetrievedBySeller,
            OrderStatus::Abandoned,
            OrderStatus::CancelledByUser,
            OrderStatus::CancelledByAdmin => 'failed',
            OrderStatus::DriverEnRoutePickup,
            OrderStatus::PickedUp,
            OrderStatus::DriverEnRouteDropoff,
            OrderStatus::DeliveryInProgress => 'driver',
            default => 'pending',
        };
    }

    private function deltaPct(int $current, int $previous): float
    {
        if ($previous === 0) {
            return $current === 0 ? 0.0 : 100.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function direction(int $current, int $previous): string
    {
        if ($current > $previous) {
            return 'up';
        }

        if ($current < $previous) {
            return 'down';
        }

        return 'flat';
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function localDayBounds(int $daysAgo, bool $endAtNow): array
    {
        $tz = $this->time->timezone();
        $start = CarbonImmutable::now($tz)->startOfDay()->subDays($daysAgo);
        $end = $endAtNow ? CarbonImmutable::now($tz) : $start->addDay();

        return [$start->setTimezone('UTC'), $end->setTimezone('UTC')];
    }
}
