<?php

declare(strict_types=1);

use App\Enums\AccountStatus;
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

it('admin suspends a user and an audit row is written', function (): void {
    Sanctum::actingAs($this->admin);
    $target = User::factory()->create(['account_status' => AccountStatus::Active->value]);

    $response = $this->postJson("/api/admin/users/{$target->public_id}/suspend", [
        'reason_code' => 'abuse',
        'detail' => 'spamming receivers',
    ]);

    $response->assertOk()->assertJsonPath('data.account_status', 'suspended');
    $this->assertDatabaseHas('account_moderation_actions', [
        'user_id' => $target->id,
        'action' => 'suspend',
        'reason_code' => 'abuse',
    ]);
});

it('rejects a non-admin', function (): void {
    Sanctum::actingAs(User::factory()->create());
    $target = User::factory()->create();

    $this->postJson("/api/admin/users/{$target->public_id}/ban", [
        'reason_code' => 'fraud',
        'detail' => 'fraud report',
    ])->assertForbidden();
});

it('rejects self-moderation', function (): void {
    Sanctum::actingAs($this->admin);

    $this->postJson("/api/admin/users/{$this->admin->public_id}/suspend", [
        'reason_code' => 'other',
        'detail' => 'self moderation',
    ])->assertStatus(422)->assertJsonPath('error', 'CANNOT_MODERATE_SELF');
});

it('validates reason_code and detail', function (): void {
    Sanctum::actingAs($this->admin);
    $target = User::factory()->create();

    $this->postJson("/api/admin/users/{$target->public_id}/suspend", [
        'reason_code' => 'not_a_reason',
        'detail' => 'x',
    ])->assertStatus(422);
});

it('reinstates and returns moderation history', function (): void {
    Sanctum::actingAs($this->admin);
    $target = User::factory()->create(['account_status' => AccountStatus::Suspended->value]);

    $this->postJson("/api/admin/users/{$target->public_id}/reinstate", [
        'reason_code' => 'other',
        'detail' => 'appeal upheld',
    ])->assertOk()->assertJsonPath('data.account_status', 'active');

    $history = $this->getJson("/api/admin/users/{$target->public_id}/moderation-history");
    $history->assertOk()->assertJsonPath('data.0.action', 'reinstate');
    $history->assertJsonPath('data.0.actor.id', $this->admin->public_id);
});
