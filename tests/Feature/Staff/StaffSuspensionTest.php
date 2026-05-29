<?php

declare(strict_types=1);

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
});

it('suspends another admin, revokes their tokens', function (): void {
    $actor = User::factory()->create(['account_status' => AccountStatus::Active->value]);
    $actor->assignRole('admin');
    $target = User::factory()->create(['account_status' => AccountStatus::Active->value]);
    $target->assignRole('admin');
    User::factory()->create(['account_status' => AccountStatus::Active->value])->assignRole('admin'); // last-admin protector
    $target->createToken('first');
    $target->createToken('second');

    Sanctum::actingAs($actor);

    $response = $this->postJson("/api/admin/staff/{$target->public_id}/suspend");

    expect($response->status())->toBe(200);
    // StaffResource is a single JsonResource -> default Laravel "data" wrapping.
    expect($response->json('data.account_status'))->toBe(AccountStatus::Suspended->value);
    expect($target->fresh()->tokens()->count())->toBe(0);
});

it('refuses self-suspension with 422 CANNOT_SELF_MODIFY', function (): void {
    $actor = User::factory()->create(['account_status' => AccountStatus::Active->value]);
    $actor->assignRole('admin');
    User::factory()->create(['account_status' => AccountStatus::Active->value])->assignRole('admin');

    Sanctum::actingAs($actor);

    $response = $this->postJson("/api/admin/staff/{$actor->public_id}/suspend");

    expect($response->status())->toBe(422);
    expect($response->json('error'))->toBe('CANNOT_SELF_MODIFY');
});

it('refuses to suspend the last admin with 422 LAST_ADMIN_PROTECTED', function (): void {
    // Only one ACTIVE admin in the system: $lastAdmin.
    $lastAdmin = User::factory()->create(['account_status' => AccountStatus::Active->value]);
    $lastAdmin->assignRole('admin');

    // Caller is a DIFFERENT admin who is suspended (so not counted as an active admin),
    // but still holds the admin role. Sanctum::actingAs bypasses the account-status gate,
    // letting us exercise the last-admin guard directly. Because the caller is not active,
    // suspending $lastAdmin would drop active admins to zero -> guard fires.
    $caller = User::factory()->create(['account_status' => AccountStatus::Suspended->value]);
    $caller->assignRole('admin');

    Sanctum::actingAs($caller);

    $response = $this->postJson("/api/admin/staff/{$lastAdmin->public_id}/suspend");

    expect($response->status())->toBe(422);
    expect($response->json('error'))->toBe('LAST_ADMIN_PROTECTED');
});
