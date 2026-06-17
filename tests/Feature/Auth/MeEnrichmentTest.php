<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => Role::findOrCreate('admin', 'web'));

it('enriches /auth/me with roles, password flag, office assignments and counts', function (): void {
    $admin = User::factory()->create(['must_change_password' => false]);
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/auth/me');

    expect($response->status())->toBe(200);
    $response->assertJsonStructure([
        'user' => ['id'],
        'roles',
        'must_change_password',
        'office_assignments',
        'counts' => ['pending_orders', 'unread_notifications'],
    ]);
    expect($response->json('roles'))->toContain('admin');
    expect($response->json('must_change_password'))->toBeFalse();
    expect($response->json('counts.pending_orders'))->toBeInt();
});
