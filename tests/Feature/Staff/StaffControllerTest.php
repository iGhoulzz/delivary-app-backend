<?php

declare(strict_types=1);

use App\Enums\AccountStatus;
use App\Models\OfficeLocation;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
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

it('creates office staff with office_assignments by office_public_id', function (): void {
    makeActingAdmin();

    $office = OfficeLocation::create([
        'region_id' => null,
        'name' => 'Office '.uniqid(),
        'address' => 'addr',
        'location' => Point::makeGeodetic(32.8872, 13.1913),
        'is_active' => true,
    ]);

    $response = $this->postJson('/api/admin/staff', [
        'phone_number' => '+218910000097',
        'first_name' => 'Ola',
        'last_name' => 'Staff',
        'role' => 'office_staff',
        'office_assignments' => [
            ['office_public_id' => $office->public_id, 'is_manager' => true],
        ],
    ]);

    expect($response->status())->toBe(201);
    expect($response->json('staff.role'))->toBe('office_staff');
});

it('rejects creating office staff with a bare internal office id', function (): void {
    makeActingAdmin();

    $office = OfficeLocation::create([
        'region_id' => null,
        'name' => 'Office '.uniqid(),
        'address' => 'addr',
        'location' => Point::makeGeodetic(32.8872, 13.1913),
        'is_active' => true,
    ]);

    $response = $this->postJson('/api/admin/staff', [
        'phone_number' => '+218910000096',
        'first_name' => 'Ola',
        'last_name' => 'Staff',
        'role' => 'office_staff',
        'office_assignments' => [
            ['office_public_id' => (string) $office->id, 'is_manager' => true],
        ],
    ]);

    expect($response->status())->toBe(422);
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
