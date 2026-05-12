<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Sms\Drivers\FakeSmsDriver;
use App\Services\Sms\Drivers\LogSmsDriver;
use App\Services\Sms\SmsService;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

final class SmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SmsService::class, function (Application $app): SmsService {
            $driver = (string) config('services.sms.driver', 'log');

            return match ($driver) {
                'log' => new LogSmsDriver($app->make(LoggerInterface::class)),
                'fake' => new FakeSmsDriver,
                default => throw new InvalidArgumentException("Unknown SMS driver [{$driver}]."),
            };
        });
    }
}
