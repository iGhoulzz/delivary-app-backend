<?php

declare(strict_types=1);

namespace App\Enums;

enum ModerationAction: string
{
    case Suspend = 'suspend';
    case Ban = 'ban';
    case Reinstate = 'reinstate';
}
