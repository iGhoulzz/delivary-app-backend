<?php

declare(strict_types=1);

use App\Models\ServiceArea;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Support\TestWorld;
use Tests\Support\TruncatesPostgisDatabase;

uses(TruncatesPostgisDatabase::class);

afterEach(function (): void {
    // Ensure a following RefreshDatabase test migrates cleanly instead of inheriting committed rows.
    RefreshDatabaseState::$migrated = false;
});

function actingAsMapZonesEtagAdmin(): User
{
    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create(['must_change_password' => false]);
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    return $admin;
}

it('changes the ETag when a zone is added, updated, or deleted', function (): void {
    actingAsMapZonesEtagAdmin();
    $world = TestWorld::create();
    $serviceArea = ServiceArea::query()->firstOrFail();

    $first = $this->getJson('/api/admin/map/zones')->assertOk()->headers->get('ETag');

    $serviceArea->update(['name' => 'Renamed']);
    $updated = $this->getJson('/api/admin/map/zones')->assertOk()->headers->get('ETag');
    expect($updated)->not->toBe($first);

    ServiceArea::create([
        'name' => 'Second',
        'boundary' => $world['region']->boundary,
        'is_active' => true,
    ]);
    $added = $this->getJson('/api/admin/map/zones')->assertOk()->headers->get('ETag');
    expect($added)->not->toBe($updated);

    $serviceArea->delete();
    $deleted = $this->getJson('/api/admin/map/zones')->assertOk()->headers->get('ETag');
    expect($deleted)->not->toBe($added);
});

it('changes the ETag for same-second updates and an office rename', function (): void {
    actingAsMapZonesEtagAdmin();
    $world = TestWorld::create();
    $serviceArea = ServiceArea::query()->firstOrFail();

    $first = $this->getJson('/api/admin/map/zones')->assertOk()->headers->get('ETag');

    $serviceArea->update(['name' => 'A']);
    $updateA = $this->getJson('/api/admin/map/zones')->assertOk()->headers->get('ETag');
    $serviceArea->update(['name' => 'B']);
    $updateB = $this->getJson('/api/admin/map/zones')->assertOk()->headers->get('ETag');
    expect($updateA)->not->toBe($first);
    expect($updateB)->not->toBe($updateA);

    $world['office']->update(['name' => 'Renamed Office']);
    $officeRenamed = $this->getJson('/api/admin/map/zones')->assertOk()->headers->get('ETag');
    expect($officeRenamed)->not->toBe($updateB);
});
