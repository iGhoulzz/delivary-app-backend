<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DriverAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DriverAccount>
 */
final class DriverAccountFactory extends Factory
{
    protected $model = DriverAccount::class;

    public function definition(): array
    {
        return [
            'driver_id' => User::factory(),
            'cash_to_deposit' => '0.00',
            'earnings_balance' => '0.00',
            'debt_balance' => '0.00',
            'max_cash_liability' => '100.00',
            'lifetime_earnings' => '0.00',
            'lifetime_cash_handled' => '0.00',
            'lifetime_platform_fees_paid' => '0.00',
        ];
    }
}
