<?php

use App\Jobs\AbandonStaleOrdersJob;
use App\Jobs\AutoOfflineIdleDriversJob;
use App\Jobs\ClearSellerEarningsJob;
use App\Jobs\EscalateBroadcastingOrdersJob;
use App\Services\Driver\AutoOfflineService;
use App\Services\Order\EscalationService;
use App\Services\Order\FailedDeliveryService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(static fn () => app(EscalateBroadcastingOrdersJob::class)->handle(app(EscalationService::class)))
    ->everyMinute()
    ->name('orders.escalate-broadcasting')
    ->withoutOverlapping();

Schedule::call(static fn () => app(AutoOfflineIdleDriversJob::class)->handle(app(AutoOfflineService::class)))
    ->everyMinute()
    ->name('drivers.auto-offline-idle')
    ->withoutOverlapping();

Schedule::call(static fn () => app(AbandonStaleOrdersJob::class)->handle(app(FailedDeliveryService::class)))
    ->daily()
    ->name('orders.abandon-stale')
    ->withoutOverlapping();

Schedule::job(new ClearSellerEarningsJob)
    ->daily()
    ->name('seller-earnings.clearance')
    ->withoutOverlapping();
