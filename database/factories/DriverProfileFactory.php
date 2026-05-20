<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DriverActivityStatus;
use App\Enums\DriverStatus;
use App\Enums\VehicleType;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DriverProfile>
 */
final class DriverProfileFactory extends Factory
{
    protected $model = DriverProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'office_id' => null,
            'status' => DriverStatus::Active->value,
            'vehicle_type' => VehicleType::Motorcycle->value,
            'vehicle_plate' => strtoupper(fake()->bothify('???-####')),
            'vehicle_color' => fake()->safeColorName(),
            'vehicle_model' => fake()->word(),
            'activity_status' => DriverActivityStatus::Offline->value,
            'current_location' => null,
            'lifetime_deliveries' => 0,
            'rating_average' => '5.00',
            'notes' => null,
        ];
    }
}
