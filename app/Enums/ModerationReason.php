<?php

declare(strict_types=1);

namespace App\Enums;

enum ModerationReason: string
{
    case Fraud = 'fraud';
    case Abuse = 'abuse';
    case NonPayment = 'non_payment';
    case PolicyViolation = 'policy_violation';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Fraud => 'Fraud',
            self::Abuse => 'Abuse',
            self::NonPayment => 'Non-payment',
            self::PolicyViolation => 'Policy violation',
            self::Other => 'Other',
        };
    }
}
