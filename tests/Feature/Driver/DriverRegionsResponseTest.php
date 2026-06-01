<?php

declare(strict_types=1);

use App\Models\DriverProfile;
use App\Models\OfficeLocation;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('driver regions response exposes office public id and no internal office_id', function (): void {
    Role::findOrCreate('driver', 'web');

    $office = OfficeLocation::create([
        'region_id' => null,
        'name' => 'Office '.uniqid(),
        'address' => 'addr',
        'location' => Point::makeGeodetic(32.8872, 13.1913),
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    $user->assignRole('driver');
    $profile = DriverProfile::factory()->create([
        'user_id' => $user->id,
        'office_id' => $office->id,
    ]);

    Sanctum::actingAs($user);

    $body = $this->getJson('/api/driver/regions')->assertStatus(200)->json();

    expect($body)->not->toHaveKey('office_id');
    expect($body)->not->toHaveKey('office_name');
    expect(data_get($body, 'office.id'))->toBe($profile->office->public_id);
    expect(data_get($body, 'office.name'))->toBe($office->name);
});
