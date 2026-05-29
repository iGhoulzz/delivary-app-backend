<?php

declare(strict_types=1);

namespace App\Services\Staff;

final class TempPasswordGenerator
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';

    private const LENGTH = 10;

    public function generate(): string
    {
        $alphabet = self::ALPHABET;
        $alphabetLen = strlen($alphabet);
        $bytes = random_bytes(self::LENGTH);
        $password = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $password .= $alphabet[ord($bytes[$i]) % $alphabetLen];
        }

        return $password;
    }
}
