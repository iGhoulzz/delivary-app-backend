<?php

declare(strict_types=1);

use App\Enums\ModerationAction;
use App\Models\AccountModerationAction;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('auto-assigns a public_id and casts enums', function (): void {
    $row = AccountModerationAction::factory()->create();

    expect($row->public_id)->not->toBeEmpty();
    expect($row->getRouteKeyName())->toBe('public_id');
    expect($row->action)->toBeInstanceOf(ModerationAction::class);
    expect($row->created_at)->not->toBeNull();
});

it('exposes target and actor relations', function (): void {
    $row = AccountModerationAction::factory()->create();

    expect($row->user)->not->toBeNull();
    expect($row->actor)->not->toBeNull();
});
