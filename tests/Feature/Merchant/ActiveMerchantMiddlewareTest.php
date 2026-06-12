<?php

declare(strict_types=1);

use App\Enums\MerchantErrorCode;
use App\Exceptions\Merchant\MerchantException;
use App\Http\Middleware\EnsureActiveMerchant;
use App\Models\MerchantProfile;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RolesSeeder::class));

function passThrough(User $user): string
{
    $request = Request::create('/merchant/orders', 'GET');
    $request->setUserResolver(fn () => $user);

    return (new EnsureActiveMerchant)
        ->handle($request, fn () => new Response('ok'))
        ->getContent();
}

it('lets an active merchant through', function () {
    $merchant = MerchantProfile::factory()->create();
    $merchant->user->assignRole('merchant');

    expect(passThrough($merchant->user))->toBe('ok');
});

it('blocks a suspended merchant', function () {
    $merchant = MerchantProfile::factory()->suspended()->create();
    $merchant->user->assignRole('merchant');

    expect(fn () => passThrough($merchant->user))
        ->toThrow(MerchantException::class)
        ->and((new MerchantException(MerchantErrorCode::MerchantNotActive, ''))->httpStatus())
        ->toBe(403);
});

it('blocks a user with no merchant profile', function () {
    $user = User::factory()->create();

    expect(fn () => passThrough($user))->toThrow(MerchantException::class);
});
