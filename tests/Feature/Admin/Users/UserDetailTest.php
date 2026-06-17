<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('user', 'web');

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

it('shows user detail with readonly notification prefs and customer order summary', function (): void {
    Sanctum::actingAs($this->admin);

    $user = User::factory()->create([
        'push_notifications_enabled' => true,
        'sms_notifications_enabled' => false,
        'email_notifications_enabled' => true,
    ]);
    $user->assignRole('user');

    Order::factory()->create(['sender_user_id' => $user->id]);
    Order::factory()->withReceiverUser($user)->create();

    $response = $this->getJson("/api/admin/users/{$user->public_id}")->assertOk();

    $response
        ->assertJsonPath('data.id', $user->public_id)
        ->assertJsonPath('data.notification_prefs.push', true)
        ->assertJsonPath('data.notification_prefs.sms', false)
        ->assertJsonPath('data.notification_prefs.email', true)
        ->assertJsonPath('data.orders_as_customer.total', 2);
});
