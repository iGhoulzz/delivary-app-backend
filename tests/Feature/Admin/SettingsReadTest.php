<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\TestWorld;

uses(RefreshDatabase::class);

it('returns grouped editable settings plus read-only modifiers for an admin', function (): void {
    TestWorld::create();
    $admin = User::factory()->create(['must_change_password' => false]);
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/admin/settings');

    expect($response->status())->toBe(200);
    $response->assertJsonStructure([
        'pricing' => [['key', 'value', 'type']],
        'payouts',
        'settlement',
        'risk',
        'read_only',
    ]);

    $pricingKeys = collect($response->json('pricing'))->pluck('key');
    expect($pricingKeys)->toContain('pricing.item_commission_rate');
    expect($pricingKeys)->not->toContain('pricing.item_size_modifiers');
});

it('forbids non-admins', function (): void {
    TestWorld::create();
    $user = User::factory()->create(['must_change_password' => false]);
    Sanctum::actingAs($user);

    $this->getJson('/api/admin/settings')->assertForbidden();
});
