<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
});

function actingAsNotificationPrefsAdmin(): User
{
    $admin = User::factory()->create(['must_change_password' => false]);
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    return $admin;
}

it('lets an admin patch user notification preferences partially', function (): void {
    $admin = actingAsNotificationPrefsAdmin();
    $target = User::factory()->create([
        'push_notifications_enabled' => true,
        'sms_notifications_enabled' => true,
        'email_notifications_enabled' => true,
    ]);

    Log::shouldReceive('info')
        ->once()
        ->with('admin.notification_prefs.updated', Mockery::on(
            fn (array $context): bool => $context['actor'] === $admin->public_id
                && $context['target'] === $target->public_id
                && $context['changed'] === ['sms']
        ));

    $response = $this->patchJson(
        "/api/admin/users/{$target->public_id}/notification-preferences",
        ['sms' => false],
    )->assertOk();

    $response
        ->assertJsonPath('notification_preferences.push', true)
        ->assertJsonPath('notification_preferences.sms', false)
        ->assertJsonPath('notification_preferences.email', true);

    $target->refresh();

    expect($target->push_notifications_enabled)->toBeTrue()
        ->and($target->sms_notifications_enabled)->toBeFalse()
        ->and($target->email_notifications_enabled)->toBeTrue();
});

it('rejects an empty notification preference patch', function (): void {
    actingAsNotificationPrefsAdmin();
    $target = User::factory()->create();

    $this->patchJson("/api/admin/users/{$target->public_id}/notification-preferences", [])
        ->assertStatus(422);
});

it('forbids non-admins from patching notification preferences', function (): void {
    $actor = User::factory()->create(['must_change_password' => false]);
    $target = User::factory()->create();
    Sanctum::actingAs($actor);

    $this->patchJson("/api/admin/users/{$target->public_id}/notification-preferences", ['sms' => false])
        ->assertForbidden();
});
