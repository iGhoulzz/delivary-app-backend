<?php

declare(strict_types=1);

use App\Models\DriverStrike;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('assigns a unique ULID public_id to a strike and routes by it', function (): void {
    $driver = User::factory()->create();

    $strike = DriverStrike::create([
        'driver_id' => $driver->id,
        'reason' => 'manual_admin',
        'issued_by' => 'admin',
        'fee_amount' => 0,
    ]);

    expect($strike->public_id)->not->toBeNull();
    expect($strike->getRouteKeyName())->toBe('public_id');

    $second = DriverStrike::create([
        'driver_id' => $driver->id,
        'reason' => 'manual_admin',
        'issued_by' => 'admin',
        'fee_amount' => 0,
    ]);

    expect($second->public_id)->not->toBe($strike->public_id);
});
