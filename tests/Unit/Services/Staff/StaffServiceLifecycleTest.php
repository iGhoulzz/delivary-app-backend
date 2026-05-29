<?php

declare(strict_types=1);

use App\Enums\AccountStatus;
use App\Exceptions\Staff\StaffDomainException;
use App\Models\User;
use App\Services\Staff\StaffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
});

function makeAdmin(): User
{
    $user = User::factory()->create([
        'account_status' => AccountStatus::Active->value,
        'must_change_password' => false,
    ]);
    $user->assignRole('admin');

    return $user;
}

it('suspends another admin, revokes their tokens, sets status', function (): void {
    $actor = makeAdmin();
    $target = makeAdmin();
    $protector = makeAdmin();
    $target->createToken('test');

    expect($target->tokens()->count())->toBe(1);

    $result = app(StaffService::class)->suspend($target, $actor);

    expect($result->account_status)->toBe(AccountStatus::Suspended);
    expect($target->tokens()->count())->toBe(0);
});

it('refuses self-suspension', function (): void {
    $actor = makeAdmin();
    makeAdmin();

    expect(fn () => app(StaffService::class)->suspend($actor, $actor))
        ->toThrow(StaffDomainException::class, 'cannot perform this action on their own');
});

it('refuses to suspend the last admin', function (): void {
    $actor = makeAdmin();
    $target = makeAdmin();

    $other = makeAdmin();
    app(StaffService::class)->suspend($other, $actor);

    app(StaffService::class)->suspend($target, $actor);

    $newAdmin = makeAdmin();
    app(StaffService::class)->suspend($newAdmin, $actor);

    $inactive = User::factory()->create(['account_status' => AccountStatus::Suspended->value]);
    $inactive->assignRole('admin');

    expect(fn () => app(StaffService::class)->suspend($actor, $inactive))
        ->toThrow(StaffDomainException::class, 'last active admin');
});

it('reinstates a suspended admin', function (): void {
    $actor = makeAdmin();
    $target = makeAdmin();
    makeAdmin();

    app(StaffService::class)->suspend($target, $actor);
    expect($target->fresh()->account_status)->toBe(AccountStatus::Suspended);

    $result = app(StaffService::class)->reinstate($target, $actor);

    expect($result->account_status)->toBe(AccountStatus::Active);
});

it('resets temp password — regenerates, sets flag, revokes tokens', function (): void {
    $actor = makeAdmin();
    $target = makeAdmin();
    $target->createToken('test');
    $oldHash = $target->password;

    $result = app(StaffService::class)->resetTempPassword($target, $actor);

    expect($result)->toHaveKeys(['user', 'temporary_password']);
    expect($result['user']->must_change_password)->toBeTrue();
    expect(Hash::check($result['temporary_password'], $result['user']->password))->toBeTrue();
    expect($result['user']->password)->not->toBe($oldHash);
    expect($target->tokens()->count())->toBe(0);
});

it('refuses self-reset of temp password', function (): void {
    $actor = makeAdmin();
    makeAdmin();

    expect(fn () => app(StaffService::class)->resetTempPassword($actor, $actor))
        ->toThrow(StaffDomainException::class, 'cannot perform this action on their own');
});
