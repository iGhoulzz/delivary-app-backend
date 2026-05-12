<?php

declare(strict_types=1);

namespace App\Services\Sms\Drivers;

use App\Services\Sms\SmsService;
use Psr\Log\LoggerInterface;

final class LogSmsDriver implements SmsService
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function send(string $phone, string $message): void
    {
        $this->logger->info('SMS_OUT', ['phone' => $phone, 'message' => $message]);
    }
}
