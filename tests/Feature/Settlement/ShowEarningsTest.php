<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('earnings response does not leak internal seller_id', function (): void {
    $seller = User::factory()->create();
    Sanctum::actingAs($seller);

    $body = $this->getJson('/api/me/earnings')->assertStatus(200)->json();

    expect($body)->not->toHaveKey('seller_id');
});
