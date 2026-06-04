<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

it('admin looks up a user by phone', function (): void {
    Sanctum::actingAs($this->admin);
    $target = User::factory()->create(['phone_number' => '+218910000111']);

    $this->getJson('/api/admin/users/lookup?phone=%2B218910000111')
        ->assertOk()
        ->assertJsonPath('data.id', $target->public_id)
        ->assertJsonPath('data.phone', '+218910000111');
});

it('returns null data for an unknown phone', function (): void {
    Sanctum::actingAs($this->admin);

    $this->getJson('/api/admin/users/lookup?phone=%2B218999999999')
        ->assertOk()
        ->assertJsonPath('data', null);
});

it('forbids non-admin lookup', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/admin/users/lookup?phone=%2B218910000111')->assertForbidden();
});
