<?php

declare(strict_types=1);

use App\Http\Resources\Moderation\UserLookupResource;
use App\Http\Resources\Moderation\UserModerationResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('user moderation resource exposes public_id as id, no internal id', function (): void {
    $user = User::factory()->create();
    $array = (new UserModerationResource($user))->resolve();

    expect($array['id'])->toBe($user->public_id);
    expect($array)->not->toHaveKey('user_id');
});

it('lookup resource returns thin identity with public_id', function (): void {
    $user = User::factory()->create();
    $array = (new UserLookupResource($user))->resolve();

    expect($array)->toHaveKeys(['id', 'name', 'phone', 'roles', 'account_status']);
    expect($array['id'])->toBe($user->public_id);
});
