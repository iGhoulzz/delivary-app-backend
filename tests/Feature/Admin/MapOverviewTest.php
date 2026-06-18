<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\TestWorld;

uses(RefreshDatabase::class);

it('returns offices and active drivers for the map', function (): void {
    TestWorld::create();
    makeOnlineDriverAt(32.8872, 13.1913);

    $admin = User::factory()->create(['must_change_password' => false]);
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/admin/map/overview');

    expect($response->status())->toBe(200);
    $response->assertJsonStructure([
        'offices' => [['id', 'name', 'location' => ['lat', 'lng']]],
        'drivers' => [['id', 'name', 'activity_status', 'location' => ['lat', 'lng'], 'active_load']],
    ]);
    expect($response->json('drivers'))->toHaveCount(1);
    expect($response->json('drivers.0.active_load'))->toBe(0);
    expect($response->json('offices'))->not->toBeEmpty();
});

it('forbids non-admins', function (): void {
    TestWorld::create();
    $user = User::factory()->create(['must_change_password' => false]);
    Sanctum::actingAs($user);

    $this->getJson('/api/admin/map/overview')->assertForbidden();
});
