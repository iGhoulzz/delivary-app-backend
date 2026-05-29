<?php

declare(strict_types=1);

use App\Exceptions\Staff\StaffDomainException;
use App\Models\User;
use App\Services\Staff\TempPasswordChangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('changes password, clears flag, revokes tokens, issues new token', function (): void {
    $user = User::factory()->create([
        'password' => Hash::make('temp1234ab'),
        'must_change_password' => true,
    ]);
    $user->createToken('old');
    $user->createToken('older');
    expect($user->tokens()->count())->toBe(2);

    $result = app(TempPasswordChangeService::class)->change(
        $user,
        'temp1234ab',
        'newSecret99X',
    );

    expect($result)->toHaveKeys(['user', 'token']);
    expect($result['user']->must_change_password)->toBeFalse();
    expect(Hash::check('newSecret99X', $result['user']->password))->toBeTrue();
    expect($result['token'])->toBeString();
    expect($user->fresh()->tokens()->count())->toBe(1);
});

it('rejects when current password is wrong', function (): void {
    $user = User::factory()->create([
        'password' => Hash::make('temp1234ab'),
        'must_change_password' => true,
    ]);

    expect(fn () => app(TempPasswordChangeService::class)->change(
        $user,
        'wrongPass',
        'newSecret99X',
    ))->toThrow(StaffDomainException::class);
});

it('rejects when new password equals current', function (): void {
    $user = User::factory()->create([
        'password' => Hash::make('temp1234ab'),
        'must_change_password' => true,
    ]);

    expect(fn () => app(TempPasswordChangeService::class)->change(
        $user,
        'temp1234ab',
        'temp1234ab',
    ))->toThrow(StaffDomainException::class, 'same');
});
