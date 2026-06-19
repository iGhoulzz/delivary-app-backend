<?php

declare(strict_types=1);

namespace App\Support\Resolvers;

use App\Models\MerchantProfile;
use App\Models\OfficeLocation;
use App\Models\User;

final class PublicIdResolver
{
    public static function officeId(?string $publicId): ?int
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }

        return (int) OfficeLocation::query()->where('public_id', $publicId)->valueOrFail('id');
    }

    public static function userId(?string $publicId): ?int
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }

        return (int) User::query()->where('public_id', $publicId)->valueOrFail('id');
    }

    public static function merchantProfileId(?string $publicId): ?int
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }

        return (int) MerchantProfile::query()->where('public_id', $publicId)->valueOrFail('id');
    }
}
