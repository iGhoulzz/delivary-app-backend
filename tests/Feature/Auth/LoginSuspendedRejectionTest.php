<?php

declare(strict_types=1);

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('suspended user cannot log in and receives 403 with account_not_loginable error', function (): void {
    User::factory()->create([
        'phone_number' => '+218910000050',
        'password' => Hash::make('correctPass1'),
        'account_status' => AccountStatus::Suspended,
        'phone_verified_at' => now(),
    ]);

    $response = $this->postJson('/api/auth/login', [
        'phone_number' => '+218910000050',
        'password' => 'correctPass1',
    ]);

    $response->assertStatus(403);
    expect($response->json('error'))->toBe('account_not_loginable');
});

test('banned user cannot log in and receives 403', function (): void {
    User::factory()->create([
        'phone_number' => '+218910000051',
        'password' => Hash::make('correctPass1'),
        'account_status' => AccountStatus::Banned,
        'phone_verified_at' => now(),
    ]);

    $response = $this->postJson('/api/auth/login', [
        'phone_number' => '+218910000051',
        'password' => 'correctPass1',
    ]);

    $response->assertStatus(403);
});

test('active verified user can log in and receives a token', function (): void {
    User::factory()->create([
        'phone_number' => '+218910000052',
        'password' => Hash::make('correctPass1'),
        'account_status' => AccountStatus::Active,
        'phone_verified_at' => now(),
    ]);

    $response = $this->postJson('/api/auth/login', [
        'phone_number' => '+218910000052',
        'password' => 'correctPass1',
    ]);

    $response->assertStatus(200);
    expect($response->json('token'))->toBeString();
});
