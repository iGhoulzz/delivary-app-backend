<?php

declare(strict_types=1);

use App\Models\DriverAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('is false for a user with no driver account', function (): void {
    expect(User::factory()->create()->hasOutstandingFees())->toBeFalse();
});

it('is false when driver debt is zero', function (): void {
    $user = User::factory()->create();
    DriverAccount::factory()->create(['driver_id' => $user->id, 'debt_balance' => '0.00']);

    expect($user->fresh()->hasOutstandingFees())->toBeFalse();
});

it('is true when driver debt is positive', function (): void {
    $user = User::factory()->create();
    DriverAccount::factory()->create(['driver_id' => $user->id, 'debt_balance' => '15.00']);

    expect($user->fresh()->hasOutstandingFees())->toBeTrue();
});
