<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('rejects unauthenticated requests to broadcasting auth', function (): void {
    $response = $this->postJson('/broadcasting/auth', [
        'channel_name' => 'private-user.1',
        'socket_id' => '1234.5678',
    ]);

    expect($response->status())->toBe(401);
});

it('accepts a valid Sanctum token at broadcasting auth', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/broadcasting/auth', [
        'channel_name' => 'private-user.'.$user->id,
        'socket_id' => '1234.5678',
    ]);

    // The route is registered; auth passes; channel callback authorizes.
    // 200 means Laravel accepted the auth + the channel callback returned truthy.
    expect($response->status())->toBe(200);
});
