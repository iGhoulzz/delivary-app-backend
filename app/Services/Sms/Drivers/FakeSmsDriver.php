<?php

declare(strict_types=1);

namespace App\Services\Sms\Drivers;

use App\Services\Sms\SmsService;
use RuntimeException;

final class FakeSmsDriver implements SmsService
{
    /** @var array<int, array{phone: string, message: string}> */
    public array $sent = [];

    public function send(string $phone, string $message): void
    {
        $this->sent[] = ['phone' => $phone, 'message' => $message];
    }

    public function assertSentTo(string $phone): void
    {
        foreach ($this->sent as $entry) {
            if ($entry['phone'] === $phone) {
                return;
            }
        }
        throw new RuntimeException("No SMS sent to {$phone}");
    }

    /**
     * Extract the most recent 6-digit numeric code from messages sent to $phone.
     * Returns null if no message sent to $phone has a 6-digit run.
     */
    public function lastCodeFor(string $phone): ?string
    {
        $messages = array_values(array_filter(
            $this->sent,
            static fn (array $e): bool => $e['phone'] === $phone,
        ));

        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (preg_match('/\b(\d{6})\b/', $messages[$i]['message'], $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }
}
