<?php

declare(strict_types=1);

use App\Models\Region;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Support\TestWorld;

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

it('returns service areas and regions as a GeoJSON FeatureCollection', function (): void {
    actingAsMapZonesAdmin();
    $world = TestWorld::create();
    $region = $world['region'];
    $office = $world['office'];

    $withoutOffice = Region::create([
        'service_area_id' => $region->service_area_id,
        'office_id' => null,
        'name' => 'No Office',
        'boundary' => $region->boundary,
        'is_active' => false,
        'base_fee' => '2.00',
    ]);

    $response = $this->getJson('/api/admin/map/zones')->assertOk();
    expect($response->json('type'))->toBe('FeatureCollection');

    $features = collect($response->json('features'));
    expect($features)->toHaveCount(3);

    $serviceArea = $features->firstWhere('properties.kind', 'service_area');
    expect($serviceArea)->not->toBeNull();
    expect($serviceArea['properties'])->toMatchArray([
        'kind' => 'service_area',
        'id' => $region->service_area_id,
        'name' => 'Test Service Area',
        'is_active' => true,
    ]);
    expect($serviceArea['properties']['id'])->toBeInt();
    expect($serviceArea['geometry']['type'])->toBe('Polygon');
    expect($serviceArea['geometry']['coordinates'][0])->toHaveCount(5);

    $withOffice = $features->firstWhere('properties.name', 'Test Region');
    expect($withOffice['properties'])->toMatchArray([
        'kind' => 'region',
        'id' => $region->id,
        'service_area_id' => $region->service_area_id,
        'base_fee' => '10.00',
        'is_active' => true,
        'office' => [
            'id' => $office->public_id,
            'name' => 'Test Office',
        ],
    ]);
    expect($withOffice['properties']['id'])->toBeInt();
    expect($withOffice['geometry']['type'])->toBe('Polygon');

    $noOffice = $features->firstWhere('properties.name', 'No Office');
    expect($noOffice['properties'])->toMatchArray([
        'id' => $withoutOffice->id,
        'is_active' => false,
        'base_fee' => '2.00',
        'office' => null,
    ]);
});

it('emits a null office when a region office is soft-deleted', function (): void {
    actingAsMapZonesAdmin();
    $world = TestWorld::create();
    $world['office']->delete();

    $features = collect($this->getJson('/api/admin/map/zones')->assertOk()->json('features'));
    $region = $features->firstWhere('properties.name', 'Test Region');

    expect($region['properties']['office'])->toBeNull();
});
