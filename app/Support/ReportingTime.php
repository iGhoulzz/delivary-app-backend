<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

final class ReportingTime
{
    public function timezone(): string
    {
        return (string) config('reporting.timezone', 'Africa/Tripoli');
    }

    /** Reporting-tz calendar date (YYYY-MM-DD) for a UTC instant. */
    public function localDate(CarbonImmutable $utc): string
    {
        return $utc->setTimezone($this->timezone())->format('Y-m-d');
    }

    /** SQL fragment: a UTC timestamp column -> reporting-tz ::date. Column is a trusted caller-supplied literal. */
    public function sqlLocalDate(string $column): string
    {
        $tz = $this->timezone();

        return "(({$column} AT TIME ZONE 'UTC') AT TIME ZONE '{$tz}')::date";
    }

    /**
     * UTC [from, to) bounds for a range token, computed on reporting-tz day edges.
     * 'today' = current local day; '7d'/'30d' = last N local days incl. today; 'all' = [epoch, now].
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function rangeBounds(string $range): array
    {
        $tz = $this->timezone();
        $now = CarbonImmutable::now($tz);
        $to = $now->setTimezone('UTC');
        $from = match ($range) {
            'today' => $now->startOfDay(),
            '7d' => $now->startOfDay()->subDays(6),
            '30d' => $now->startOfDay()->subDays(29),
            'all' => CarbonImmutable::createFromTimestamp(0, $tz),
            default => $now->startOfDay()->subDays(29),
        };

        return [$from->setTimezone('UTC'), $to];
    }
}
