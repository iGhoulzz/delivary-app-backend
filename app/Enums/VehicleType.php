<?php

declare(strict_types=1);

namespace App\Enums;

enum VehicleType: string
{
    case Car = 'car';
    case Motorcycle = 'motorcycle';

    public function label(): string
    {
        return match ($this) {
            self::Car => 'Car',
            self::Motorcycle => 'Motorcycle',
        };
    }

    /**
     * Vehicle types eligible to deliver an order of the given item size.
     *
     * Sizes: small, medium, large, xlarge (per spec section 10.5).
     *
     * @return array<int, self>
     */
    public static function eligibleFor(string $itemSize): array
    {
        return match ($itemSize) {
            'small', 'medium' => [self::Motorcycle, self::Car],
            'large', 'xlarge' => [self::Car],
            default => [self::Car],
        };
    }
}
