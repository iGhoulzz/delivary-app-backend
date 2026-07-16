<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
});

function actingAsMapZonesAdmin(): User
{
    $admin = User::factory()->create(['must_change_password' => false]);
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    return $admin;
}

it('rejects an unauthenticated request', function (): void {
    $this->getJson('/api/admin/map/zones')->assertUnauthorized();
});

it('rejects a non-admin', function (): void {
    Sanctum::actingAs(User::factory()->create(['must_change_password' => false]));

    $this->getJson('/api/admin/map/zones')->assertForbidden();
});

it('rejects an admin who must change password', function (): void {
    $admin = User::factory()->create(['must_change_password' => true]);
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $this->getJson('/api/admin/map/zones')->assertForbidden();
});

it('allows an admin to request the zone collection', function (): void {
    actingAsMapZonesAdmin();

    $this->getJson('/api/admin/map/zones')
        ->assertOk()
        ->assertExactJson([
            'type' => 'FeatureCollection',
            'features' => [],
        ]);
});
