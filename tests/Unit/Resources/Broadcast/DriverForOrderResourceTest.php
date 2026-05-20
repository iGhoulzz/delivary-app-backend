<?php

declare(strict_types=1);

use App\Enums\VehicleType;
use App\Http\Resources\Broadcast\DriverForOrderResource;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exposes only sender-safe driver fields', function (): void {
    $driver = User::factory()->create(['first_name' => 'Yusuf']);
    /** @var DriverProfile $profile */
    $profile = DriverProfile::factory()->create([
        'user_id' => $driver->id,
        'vehicle_type' => VehicleType::Motorcycle->value,
        'vehicle_plate' => 'TRP-9999',
        'office_id' => null,
    ]);

    $array = (new DriverForOrderResource($profile->fresh(['user'])))->resolve();

    expect($array)->toHaveKey('first_name', 'Yusuf');
    expect($array)->toHaveKey('vehicle_type', VehicleType::Motorcycle->value);
    expect($array)->toHaveKey('vehicle_color');
    expect($array)->toHaveKey('rating_average');
    expect($array)->toHaveKey('lifetime_deliveries');
    expect($array)->toHaveKey('current_location');

    // Internal fields must NOT leak
    expect($array)->not->toHaveKey('id');
    expect($array)->not->toHaveKey('user_id');
    expect($array)->not->toHaveKey('office_id');
    expect($array)->not->toHaveKey('vehicle_plate');
    expect($array)->not->toHaveKey('status');
    expect($array)->not->toHaveKey('activity_status');
});
