<?php

declare(strict_types=1);

namespace App\Enums;

enum ReturnReason: string
{
    case ReceiverRefused = 'receiver_refused';
    case ReceiverUnreachable = 'receiver_unreachable';
    case AddressInvalid = 'address_invalid';
    case ItemDamaged = 'item_damaged';
    case DriverFault = 'driver_fault';

    public function label(): string
    {
        return match ($this) {
            self::ReceiverRefused => 'Receiver Refused',
            self::ReceiverUnreachable => 'Receiver Unreachable',
            self::AddressInvalid => 'Address Invalid',
            self::ItemDamaged => 'Item Damaged',
            self::DriverFault => 'Driver Fault',
        };
    }
}
