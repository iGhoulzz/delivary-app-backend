<?php

declare(strict_types=1);

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
});

it('admin resets another admins password — returns new temp once, tokens revoked', function (): void {
    $actor = User::factory()->create(['account_status' => AccountStatus::Active->value]);
    $actor->assignRole('admin');
    $target = User::factory()->create([
        'account_status' => AccountStatus::Active->value,
        'must_change_password' => false,
        'password' => Hash::make('originalPass1'),
    ]);
    $target->assignRole('admin');
    $target->createToken('old');

    Sanctum::actingAs($actor);

    $response = $this->postJson("/api/admin/staff/{$target->public_id}/reset-temp-password");

    expect($response->status())->toBe(200);
    expect($response->json('temporary_password'))->toBeString();
    expect(strlen($response->json('temporary_password')))->toBe(10);

    $fresh = $target->fresh();
    expect($fresh->must_change_password)->toBeTrue();
    expect(Hash::check($response->json('temporary_password'), $fresh->password))->toBeTrue();
    expect($fresh->tokens()->count())->toBe(0);
});

it('staff with must_change_password=true is blocked from other endpoints', function (): void {
    $admin = User::factory()->create([
        'account_status' => AccountStatus::Active->value,
        'must_change_password' => true,
    ]);
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/admin/staff');

    expect($response->status())->toBe(403);
    expect($response->json('error'))->toBe('password_change_required');
});

it('change-from-temp lets the user back in', function (): void {
    $admin = User::factory()->create([
        'account_status' => AccountStatus::Active->value,
        'must_change_password' => true,
        'password' => Hash::make('tempPass123'),
    ]);
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $response = $this->postJson('/api/me/password/change-from-temp', [
        'current_password' => 'tempPass123',
        'new_password' => 'newSecret9X1',
        'new_password_confirmation' => 'newSecret9X1',
    ]);

    expect($response->status())->toBe(200);
    expect($response->json('token'))->toBeString();
    expect($admin->fresh()->must_change_password)->toBeFalse();
});
