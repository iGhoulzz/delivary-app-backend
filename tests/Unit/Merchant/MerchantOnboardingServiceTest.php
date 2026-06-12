<?php

declare(strict_types=1);

use App\Enums\AccountStatus;
use App\Enums\MerchantErrorCode;
use App\Enums\MerchantStatus;
use App\Exceptions\Merchant\MerchantException;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\Merchant\MerchantOnboardingService;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('merchant', 'web');
    $this->admin = User::factory()->create(['account_status' => AccountStatus::Active->value]);
    $this->admin->assignRole('admin');
    $this->service = app(MerchantOnboardingService::class);
});

function merchantPayload(User $user, array $overrides = []): array
{
    return array_merge([
        'user_public_id' => $user->public_id,
        'business_name' => 'Acme Shop',
        'business_phone' => '+218910000222',
        'commission_rate_override' => '0.0500',
        'driver_fee_cut_override' => null,
        'default_pickup_address' => 'Tripoli Center',
        'default_pickup_location' => ['lat' => 32.8872, 'lng' => 13.1913],
        'notes' => 'priority merchant',
    ], $overrides);
}

it('creates an active merchant and assigns the merchant role', function (): void {
    $user = User::factory()->create(['account_status' => AccountStatus::Active->value]);

    $merchant = $this->service->create($this->admin, merchantPayload($user));

    expect($merchant->user_id)->toBe($user->id)
        ->and($merchant->status)->toBe(MerchantStatus::Active)
        ->and($merchant->created_by_admin_id)->toBe($this->admin->id)
        ->and($merchant->approved_by_admin_id)->toBe($this->admin->id)
        ->and((string) $merchant->commission_rate_override)->toBe('0.0500')
        ->and($merchant->default_pickup_location->getLatitude())->toBe(32.8872)
        ->and($merchant->default_pickup_location->getLongitude())->toBe(13.1913)
        ->and($user->fresh()->hasRole('merchant'))->toBeTrue();
});

it('throws user not found for an unknown public id', function (): void {
    try {
        $this->service->create($this->admin, [
            ...merchantPayload(User::factory()->make()),
            'user_public_id' => '01HZ0000000000000000000000',
        ]);
    } catch (MerchantException $e) {
        expect($e->errorCode)->toBe(MerchantErrorCode::UserNotFound);

        return;
    }

    $this->fail('Expected merchant exception.');
});

it('rejects an existing live profile', function (): void {
    $profile = MerchantProfile::factory()->create();

    try {
        $this->service->create($this->admin, merchantPayload($profile->user));
    } catch (MerchantException $e) {
        expect($e->errorCode)->toBe(MerchantErrorCode::AlreadyMerchant);

        return;
    }

    $this->fail('Expected merchant exception.');
});

it('restores a soft-deleted non-banned profile', function (): void {
    $profile = MerchantProfile::factory()->suspended()->create([
        'business_name' => 'Old Name',
    ]);
    $profile->delete();

    $merchant = $this->service->create($this->admin, merchantPayload($profile->user, [
        'business_name' => 'Restored Name',
    ]));

    expect($merchant->id)->toBe($profile->id)
        ->and($merchant->trashed())->toBeFalse()
        ->and($merchant->status)->toBe(MerchantStatus::Active)
        ->and($merchant->business_name)->toBe('Restored Name')
        ->and($profile->user->fresh()->hasRole('merchant'))->toBeTrue();
});

it('does not restore a soft-deleted banned profile', function (): void {
    $profile = MerchantProfile::factory()->banned()->create();
    $profile->delete();

    try {
        $this->service->create($this->admin, merchantPayload($profile->user));
    } catch (MerchantException $e) {
        expect($e->errorCode)->toBe(MerchantErrorCode::AlreadyMerchant);

        return;
    }

    $this->fail('Expected merchant exception.');
});

it('rejects a banned account before inspecting a trashed profile', function (): void {
    $user = User::factory()->create(['account_status' => AccountStatus::Banned->value]);
    $profile = MerchantProfile::factory()->suspended()->create(['user_id' => $user->id]);
    $profile->delete();

    try {
        $this->service->create($this->admin, merchantPayload($user));
    } catch (MerchantException $e) {
        expect($e->errorCode)->toBe(MerchantErrorCode::AccountNotEligible)
            ->and(MerchantProfile::withTrashed()->find($profile->id)->trashed())->toBeTrue();

        return;
    }

    $this->fail('Expected merchant exception.');
});

it('suspends, reactivates, and bans with role handling', function (): void {
    $merchant = MerchantProfile::factory()->create();
    $merchant->user->assignRole('merchant');

    $suspended = $this->service->suspend($this->admin, $merchant);
    expect($suspended->status)->toBe(MerchantStatus::Suspended)
        ->and($merchant->user->fresh()->hasRole('merchant'))->toBeTrue();

    $active = $this->service->reactivate($this->admin, $suspended);
    expect($active->status)->toBe(MerchantStatus::Active)
        ->and($merchant->user->fresh()->hasRole('merchant'))->toBeTrue();

    $banned = $this->service->ban($this->admin, $active);
    expect($banned->status)->toBe(MerchantStatus::Banned)
        ->and($merchant->user->fresh()->hasRole('merchant'))->toBeFalse();
});

it('rejects invalid lifecycle transitions against the locked row', function (): void {
    $merchant = MerchantProfile::factory()->create();
    DB::table('merchant_profiles')->where('id', $merchant->id)->update([
        'status' => MerchantStatus::Suspended->value,
    ]);

    $this->service->suspend($this->admin, $merchant);
})->throws(MerchantException::class);

it('updates business details and converts default pickup coordinates in lat lng order', function (): void {
    $merchant = MerchantProfile::factory()->create([
        'default_pickup_location' => Point::makeGeodetic(32.1, 13.1),
    ]);

    $updated = $this->service->update($this->admin, $merchant, [
        'business_name' => 'New Name',
        'default_pickup_location' => ['lat' => 32.8872, 'lng' => 13.1913],
    ]);

    expect($updated->business_name)->toBe('New Name')
        ->and($updated->default_pickup_location->getLatitude())->toBe(32.8872)
        ->and($updated->default_pickup_location->getLongitude())->toBe(13.1913);
});
