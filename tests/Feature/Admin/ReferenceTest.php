<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\TestWorld;

uses(RefreshDatabase::class);

it('returns offices, regions and enum catalogs for an admin', function (): void {
    TestWorld::create();
    $admin = User::factory()->create(['must_change_password' => false]);
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/admin/reference');

    expect($response->status())->toBe(200);
    $response->assertJsonStructure([
        'offices' => [['id', 'name']],
        'regions' => [['id', 'name']],
        'enums' => [
            'driver_status' => [['value', 'label']],
            'account_status',
            'merchant_status',
            'order_status',
            'order_type',
            'strike_reason',
            'moderation_reason',
            'vehicle_type',
            'document_type',
        ],
    ]);
    expect($response->json('offices'))->not->toBeEmpty();
    expect($response->json('regions'))->not->toBeEmpty();
});

it('forbids non-admins', function (): void {
    TestWorld::create();
    $user = User::factory()->create(['must_change_password' => false]);
    Sanctum::actingAs($user);

    $this->getJson('/api/admin/reference')->assertForbidden();
});
