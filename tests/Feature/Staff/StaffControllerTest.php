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
    Role::findOrCreate('office_staff', 'web');
});

function makeActingAdmin(): User
{
    $admin = User::factory()->create(['account_status' => AccountStatus::Active->value]);
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    return $admin;
}

it('creates an admin via POST /api/admin/staff and returns temp password ONCE', function (): void {
    makeActingAdmin();

    $response = $this->postJson('/api/admin/staff', [
        'phone_number' => '+218910000099',
        'first_name' => 'Aya',
        'last_name' => 'Smith',
        'role' => 'admin',
    ]);

    expect($response->status())->toBe(201);
    expect($response->json('staff.role'))->toBe('admin');
    expect($response->json('temporary_password'))->toBeString();
    expect(strlen($response->json('temporary_password')))->toBe(10);

    $publicId = $response->json('staff.id');
    $show = $this->getJson("/api/admin/staff/{$publicId}");
    expect($show->json())->not->toHaveKey('temporary_password');
});

it('rejects non-admin actors with 403', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/admin/staff', [
        'phone_number' => '+218910000098',
        'first_name' => 'X',
        'last_name' => 'Y',
        'role' => 'admin',
    ]);

    expect($response->status())->toBe(403);
});

it('lists staff with role filter', function (): void {
    makeActingAdmin();
    $other = User::factory()->create();
    $other->assignRole('admin');

    $response = $this->getJson('/api/admin/staff?role=admin');

    expect($response->status())->toBe(200);
    expect($response->json('data'))->toHaveCount(2);
});
