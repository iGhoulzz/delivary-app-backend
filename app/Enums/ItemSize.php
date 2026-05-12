<?php

declare(strict_types=1);

namespace App\Enums;

enum ItemSize: string
{
    case Small = 'small';
    case Medium = 'medium';
    case Large = 'large';
    case XLarge = 'xlarge';

    public function label(): string
    {
        return match ($this) {
            self::Small => 'Small',
            self::Medium => 'Medium',
            self::Large => 'Large',
            self::XLarge => 'Extra Large',
        };
    }

    /**
     * Vehicle types eligible to deliver this item size.
     *
     * @return array<int, VehicleType>
     */
    public function eligibleVehicleTypes(): array
    {
        return VehicleType::eligibleFor($this->value);
    }
}
