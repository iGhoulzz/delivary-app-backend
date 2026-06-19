<?php

declare(strict_types=1);

use App\Models\DriverAccount;
use App\Models\DriverAccountTransaction;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('driver', 'web');
});

function adminDriverWithAccount(array $accountAttrs = []): array
{
    $admin = User::factory()->create(['must_change_password' => false]);
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $driver = User::factory()->create();
    $driver->assignRole('driver');
    DriverProfile::factory()->create(['user_id' => $driver->id]);
    DriverAccount::factory()->create(array_merge(['driver_id' => $driver->id], $accountAttrs));

    return [$admin, $driver];
}

it('applies an audited manual adjustment through the ledger', function (): void {
    [$admin, $driver] = adminDriverWithAccount(['debt_balance' => '50.00']);

    $response = $this->postJson("/api/admin/drivers/{$driver->public_id}/account/adjust", [
        'bucket' => 'debt_balance',
        'amount' => '-25.00',
        'note' => 'goodwill',
    ]);

    expect($response->status())->toBe(200);
    expect((string) $driver->driverAccount()->first()->debt_balance)->toBe('25.00');
    expect(DriverAccountTransaction::where('driver_id', $driver->id)
        ->where('reason', 'manual_adjustment')
        ->where('created_by_admin_id', $admin->id)->exists())->toBeTrue();
});

it('rejects (422) an adjustment that would drive a bucket negative and leaves it unchanged', function (): void {
    [, $driver] = adminDriverWithAccount(['debt_balance' => '10.00']);

    $this->postJson("/api/admin/drivers/{$driver->public_id}/account/adjust", [
        'bucket' => 'debt_balance',
        'amount' => '-25.00',
    ])->assertStatus(422);

    expect((string) $driver->driverAccount()->first()->debt_balance)->toBe('10.00');
    expect(DriverAccountTransaction::where('driver_id', $driver->id)->exists())->toBeFalse();
});

it('404s when the user has no driver account', function (): void {
    $admin = User::factory()->create(['must_change_password' => false]);
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);
    $user = User::factory()->create();

    $this->postJson("/api/admin/drivers/{$user->public_id}/account/adjust", [
        'bucket' => 'debt_balance',
        'amount' => '5',
    ])->assertStatus(404);
});

it('forbids non-admins', function (): void {
    $driver = User::factory()->create();
    $driver->assignRole('driver');
    DriverProfile::factory()->create(['user_id' => $driver->id]);
    DriverAccount::factory()->create(['driver_id' => $driver->id]);

    Sanctum::actingAs(User::factory()->create(['must_change_password' => false]));

    $this->postJson("/api/admin/drivers/{$driver->public_id}/account/adjust", [
        'bucket' => 'debt_balance',
        'amount' => '5',
    ])->assertForbidden();
});
