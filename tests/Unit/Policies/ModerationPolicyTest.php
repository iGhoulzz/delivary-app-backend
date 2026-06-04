<?php

declare(strict_types=1);

use App\Models\User;
use App\Policies\ModerationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn (): Spatie\Permission\Contracts\Role => Role::findOrCreate('admin', 'web'));

it('allows an admin to moderate another user', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    expect((new ModerationPolicy)->moderate($admin, User::factory()->create()))->toBeTrue();
});

it('forbids a non-admin', function (): void {
    expect((new ModerationPolicy)->moderate(User::factory()->create(), User::factory()->create()))->toBeFalse();
});

it('leaves self-moderation to the service domain guard', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    expect((new ModerationPolicy)->moderate($admin, $admin))->toBeTrue();
});
