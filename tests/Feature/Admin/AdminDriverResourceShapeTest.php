<?php

declare(strict_types=1);

use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('driver index exposes user public_id as id and nested office, no internal ids', function (): void {
    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $profile = DriverProfile::factory()->create();

    $response = $this->getJson('/api/admin/drivers');
    $response->assertStatus(200);

    $row = $response->json('data.0');
    expect($row['id'])->toBe($profile->user->public_id);
    expect($row)->not->toHaveKey('user_id');
    expect($row)->not->toHaveKey('office_id');
});
