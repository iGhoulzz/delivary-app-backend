<?php

declare(strict_types=1);
use App\Support\ReportingTime;
use Carbon\CarbonImmutable;

it('computes reporting-tz day boundaries and the sql tz expression', function () {
    config()->set('reporting.timezone', 'Africa/Tripoli');
    $rt = new ReportingTime;

    // 23:00 UTC on the 21st is 01:00 Tripoli on the 22nd -> "today" = 22nd local
    expect($rt->localDate(CarbonImmutable::parse('2026-06-21T23:00:00Z')))->toBe('2026-06-22');
    expect($rt->sqlLocalDate('orders.created_at'))->toContain("AT TIME ZONE 'Africa/Tripoli'");
    [$from, $to] = $rt->rangeBounds('7d');
    expect($to->greaterThan($from))->toBeTrue();
});
