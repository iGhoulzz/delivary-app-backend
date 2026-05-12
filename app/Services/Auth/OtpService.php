<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\OtpPurpose;
use App\Models\PlatformSetting;
use App\Services\Sms\SmsService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Hash;

final class OtpService
{
    public function __construct(
        private readonly SmsService $sms,
        private readonly CacheRepository $cache,
    ) {}

    public function issue(string $phone, OtpPurpose $purpose): void
    {
        $code = $this->generateCode();

        $this->cache->put(
            $purpose->cacheKeyFor($phone),
            ['code' => Hash::make($code), 'attempts' => 0],
            $this->ttlSeconds(),
        );

        $this->sms->send($phone, str_replace(':code', $code, $purpose->smsTemplate()));
    }

    public function verify(string $phone, string $candidate, OtpPurpose $purpose): bool
    {
        $key = $purpose->cacheKeyFor($phone);
        $entry = $this->cache->get($key);
        if ($entry === null) {
            return false;
        }

        // Already at the cap: invalidate without revealing whether the code matched.
        if ($entry['attempts'] >= $this->maxAttempts()) {
            $this->cache->forget($key);

            return false;
        }

        // Increment first so a hash exception still counts the attempt.
        $this->cache->put($key, [
            'code' => $entry['code'],
            'attempts' => $entry['attempts'] + 1,
        ], $this->ttlSeconds());

        if (! Hash::check($candidate, $entry['code'])) {
            return false;
        }

        // Success — burn the code so it can't be replayed.
        $this->cache->forget($key);

        return true;
    }

    private function generateCode(): string
    {
        $length = (int) PlatformSetting::get('otp_code_length', 6);

        return str_pad(
            (string) random_int(0, (10 ** $length) - 1),
            $length,
            '0',
            STR_PAD_LEFT,
        );
    }

    private function ttlSeconds(): int
    {
        return (int) PlatformSetting::get('otp_ttl_seconds', 300);
    }

    private function maxAttempts(): int
    {
        return (int) PlatformSetting::get('otp_max_attempts_per_code', 5);
    }
}
