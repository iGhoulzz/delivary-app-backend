<?php

declare(strict_types=1);

use App\Enums\DriverActivityStatus;
use App\Enums\ItemSize;
use App\Enums\VehicleType;
use App\Models\DriverProfile;
use App\Services\Order\BroadcastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Spatie roles are seeded in production via RolesSeeder.
    // Ensure the 'driver' role exists for each test run.
    Role::findOrCreate('driver', 'web');
});

it('returns online drivers within tier radius for the order vehicle class', function (): void {
    $order = makeOrderAt(32.8872, 13.1913); // Tripoli centre
    $near = makeOnlineDriverAt(32.8880, 13.1920); // ~100m away
    $far = makeOnlineDriverAt(33.0000, 13.5000); // ~30km away — outside tier-1 radius

    $service = app(BroadcastService::class);

    $eligible = $service->eligibleDriversFor($order->fresh());

    expect($eligible->pluck('user_id'))->toContain($near->id);
    expect($eligible->pluck('user_id'))->not->toContain($far->id);
});

it('excludes offline drivers', function (): void {
    $order = makeOrderAt(32.8872, 13.1913);
    $online = makeOnlineDriverAt(32.8880, 13.1920);

    $offline = makeOnlineDriverAt(32.8880, 13.1921);
    DriverProfile::query()->where('user_id', $offline->id)->update([
        'activity_status' => DriverActivityStatus::Offline->value,
    ]);

    $eligible = app(BroadcastService::class)->eligibleDriversFor($order->fresh());

    expect($eligible->pluck('user_id'))->toContain($online->id);
    expect($eligible->pluck('user_id'))->not->toContain($offline->id);
});

it('excludes drivers whose vehicle cannot carry the item size', function (): void {
    $order = makeOrderAt(32.8872, 13.1913, ItemSize::Large->value); // large needs car (not motorcycle)
    $bike = makeOnlineDriverAt(32.8880, 13.1920, VehicleType::Motorcycle);
    $car = makeOnlineDriverAt(32.8881, 13.1921, VehicleType::Car);

    $eligible = app(BroadcastService::class)->eligibleDriversFor($order->fresh());

    expect($eligible->pluck('user_id'))->not->toContain($bike->id);
    expect($eligible->pluck('user_id'))->toContain($car->id);
});
