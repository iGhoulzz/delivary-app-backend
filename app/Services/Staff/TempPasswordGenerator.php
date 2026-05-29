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
        $password = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $password .= $alphabet[random_int(0, $alphabetLen - 1)];
        }

        return $password;
    }
}
