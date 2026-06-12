<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MerchantStatus;
use App\Models\MerchantProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MerchantProfile>
 */
final class MerchantProfileFactory extends Factory
{
    protected $model = MerchantProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'business_name' => fake()->company(),
            'business_phone' => null,
            'status' => MerchantStatus::Active->value,
            'created_by_admin_id' => null,
            'approved_at' => now(),
            'approved_by_admin_id' => null,
            'commission_rate_override' => null,
            'driver_fee_cut_override' => null,
            'default_pickup_address' => null,
            'default_pickup_location' => null,
            'notes' => null,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => MerchantStatus::Suspended->value]);
    }

    public function banned(): static
    {
        return $this->state(fn (): array => ['status' => MerchantStatus::Banned->value]);
    }
}
