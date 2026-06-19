<?php

declare(strict_types=1);

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\TestWorld;

uses(RefreshDatabase::class);

function actingAsSettingsAdmin(): User
{
    $admin = User::factory()->create(['must_change_password' => false]);
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    return $admin;
}

it('updates a whitelisted rate within range and audits the writer', function (): void {
    TestWorld::create();
    $admin = actingAsSettingsAdmin();

    $response = $this->patchJson('/api/admin/settings', [
        'settings' => [['key' => 'pricing.item_commission_rate', 'value' => 0.2]],
    ]);

    expect($response->status())->toBe(200);
    expect((float) PlatformSetting::get('pricing.item_commission_rate'))->toBe(0.2);
    expect(PlatformSetting::query()->where('key', 'pricing.item_commission_rate')->value('updated_by_admin_id'))
        ->toBe($admin->id);
});

it('rejects an out-of-range rate', function (): void {
    TestWorld::create();
    actingAsSettingsAdmin();

    $this->patchJson('/api/admin/settings', [
        'settings' => [['key' => 'pricing.item_commission_rate', 'value' => 1.5]],
    ])->assertStatus(422);
});

it('rejects a key that is not in the editable catalog', function (): void {
    TestWorld::create();
    actingAsSettingsAdmin();

    $this->patchJson('/api/admin/settings', [
        'settings' => [['key' => 'codes.enforce_pickup', 'value' => false]],
    ])->assertStatus(422);
});

it('forbids non-admins', function (): void {
    TestWorld::create();
    $user = User::factory()->create(['must_change_password' => false]);
    Sanctum::actingAs($user);

    $this->patchJson('/api/admin/settings', [
        'settings' => [['key' => 'pricing.item_commission_rate', 'value' => 0.2]],
    ])->assertForbidden();
});
