<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Models\OfficeLocation;
use App\Models\Order;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function makeReturnOffice(): OfficeLocation
{
    return OfficeLocation::create([
        'region_id' => null,
        'name' => 'Return Office '.uniqid(),
        'address' => 'addr',
        'location' => Point::makeGeodetic(32.8872, 13.1913),
        'is_active' => true,
    ]);
}

it('order return block exposes return_office public_id, not internal office_id', function (): void {
    $sender = User::factory()->create();
    Sanctum::actingAs($sender);

    $office = makeReturnOffice();
    $order = Order::factory()->create([
        'sender_user_id' => $sender->id,
        'status' => OrderStatus::AtOffice->value,
        'return_office_id' => $office->id,
    ]);

    $response = $this->getJson("/api/me/orders/{$order->public_id}");
    $response->assertStatus(200);

    $ret = $response->json('data.return');
    expect($ret)->not->toBeNull();
    expect($ret)->not->toHaveKey('office_id');
    expect($ret['return_office']['id'])->toBe($office->public_id);
    expect($ret['return_office']['name'])->toBe($office->name);
});
