<?php

use App\Enums\DriverActivityStatus;
use App\Enums\DriverStatus;
use App\Enums\OrderStatus;
use App\Enums\VehicleType;
use App\Models\DriverAccount;
use App\Models\DriverProfile;
use App\Models\Order;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Real-time milestone helpers
|--------------------------------------------------------------------------
|
| Used by tests in tests/Unit/Services/Order, tests/Feature/Realtime, and
| tests/Unit/Listeners. Declared globally here so each test file can call
| them without redeclaration errors.
|
*/

function makeOnlineDriverAt(float $lat, float $lng, ?VehicleType $vehicle = null): User
{
    $vehicle ??= VehicleType::Motorcycle;

    $user = User::factory()->create();
    $user->assignRole('driver');

    DriverProfile::factory()->create([
        'user_id' => $user->id,
        'status' => DriverStatus::Active->value,
        'activity_status' => DriverActivityStatus::Online->value,
        'vehicle_type' => $vehicle->value,
        'current_location' => Point::makeGeodetic($lat, $lng),
        'last_location_updated_at' => now(),
    ]);

    DriverAccount::factory()->create([
        'driver_id' => $user->id,
        'max_cash_liability' => '200.00',
        'cash_to_deposit' => '0.00',
    ]);

    return $user;
}

function makeOrderAt(float $lat, float $lng, string $itemSize = 'small'): Order
{
    return Order::factory()->create([
        'status' => OrderStatus::AwaitingDriver->value,
        'driver_id' => null,
        'pickup_location' => Point::makeGeodetic($lat, $lng),
        'item_size' => $itemSize,
        'search_radius_tier' => 1,
    ]);
}
