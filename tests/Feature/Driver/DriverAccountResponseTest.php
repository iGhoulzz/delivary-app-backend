<?php

declare(strict_types=1);

use App\Enums\DriverAccountBucket;
use App\Enums\DriverAccountTransactionReason;
use App\Models\DriverAccount;
use App\Models\DriverAccountTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('driver account transactions expose no internal id', function (): void {
    Role::findOrCreate('driver', 'web');

    $user = User::factory()->create();
    $user->assignRole('driver');

    DriverAccount::factory()->create(['driver_id' => $user->id]);

    DriverAccountTransaction::create([
        'driver_id' => $user->id,
        'bucket' => DriverAccountBucket::EarningsBalance->value,
        'amount' => '12.50',
        'reason' => DriverAccountTransactionReason::OrderCompleted->value,
        'balance_after' => '12.50',
    ]);

    Sanctum::actingAs($user);

    $txns = $this->getJson('/api/driver/account')->assertStatus(200)->json('transactions');

    expect($txns)->toBeArray()->not->toBeEmpty();
    expect($txns[0])->not->toHaveKey('id');
    expect($txns[0])->toHaveKeys(['bucket', 'amount', 'reason', 'balance_after', 'created_at']);
});
