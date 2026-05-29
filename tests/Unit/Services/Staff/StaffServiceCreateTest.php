<?php

declare(strict_types=1);

use App\Enums\AccountStatus;
use App\Models\User;
use App\Services\Staff\StaffService;
use App\Support\DTO\CreateStaffInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('office_staff', 'web');
});

it('creates an admin user with a generated temp password', function (): void {
    $actor = User::factory()->create();
    $actor->assignRole('admin');

    $service = app(StaffService::class);

    $result = $service->create(new CreateStaffInput(
        phoneNumber: '+218910000010',
        firstName: 'Aya',
        lastName: 'Smith',
        email: null,
        role: 'admin',
    ), $actor);

    expect($result)->toHaveKeys(['user', 'temporary_password']);
    expect($result['user'])->toBeInstanceOf(User::class);
    expect($result['user']->first_name)->toBe('Aya');
    expect($result['user']->phone_number)->toBe('+218910000010');
    expect($result['user']->account_status)->toBe(AccountStatus::Active);
    expect($result['user']->must_change_password)->toBeTrue();
    expect($result['user']->hasRole('admin'))->toBeTrue();
    expect($result['temporary_password'])->toBeString();
    expect(strlen($result['temporary_password']))->toBe(10);
    expect(Hash::check($result['temporary_password'], $result['user']->password))->toBeTrue();
});
