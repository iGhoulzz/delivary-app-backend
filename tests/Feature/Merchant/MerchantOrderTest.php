<?php

declare(strict_types=1);

use App\Enums\ItemSize;
use App\Enums\MerchantStatus;
use App\Enums\OrderType;
use App\Models\MerchantProfile;
use App\Models\Order;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestWorld;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->world = TestWorld::create();

    $this->activeMerchant = function (array $attrs = []): MerchantProfile {
        $m = MerchantProfile::factory()->create(array_merge([
            'business_name' => 'Acme Shop',
            'business_phone' => '+218915550000',
        ], $attrs));
        $m->user->assignRole('merchant');

        return $m;
    };

    $this->quotePayload = function (string $itemPrice): array {
        return [
            'pickup_location' => $this->world['pickup'],
            'pickup_address' => 'Shop front',
            'receiver_location' => $this->world['dropoff'],
            'item_size' => ItemSize::Small->value,
            'item_price' => $itemPrice,
        ];
    };

    $this->createPayload = function (string $quoteToken, string $itemPrice): array {
        return [
            'quote_token' => $quoteToken,
            'item_price' => $itemPrice,
            'pickup_location' => $this->world['pickup'],
            'pickup_address' => 'Shop front',
            'receiver_location' => $this->world['dropoff'],
            'receiver_address' => 'Customer address',
            'receiver_phone' => '+218910000222',
            'receiver_name' => 'Customer',
            'item_size' => ItemSize::Small->value,
            'item_description' => 'A box of goods',
        ];
    };
});

it('lets an active merchant quote and create a merchant_delivery order with commission snapshotted', function () {
    $merchant = ($this->activeMerchant)(['commission_rate_override' => '0.0500']);

    $quote = $this->actingAs($merchant->user)
        ->postJson('/api/merchant/orders/quote', ($this->quotePayload)('100.00'))
        ->assertOk()
        ->json('data.quote_token');

    $response = $this->actingAs($merchant->user)
        ->postJson('/api/merchant/orders', ($this->createPayload)($quote, '100.00'))
        ->assertCreated();

    $order = Order::query()->latest('id')->firstOrFail();
    expect($order->order_type)->toBe(OrderType::MerchantDelivery)
        ->and($order->merchant_profile_id)->toBe($merchant->id)
        ->and($order->sender_name)->toBe('Acme Shop')
        ->and((string) $order->commission_amount)->toBe('5.00');
});

it('allows item_price = 0 (pure fulfillment)', function () {
    $merchant = ($this->activeMerchant)();

    $quote = $this->actingAs($merchant->user)
        ->postJson('/api/merchant/orders/quote', ($this->quotePayload)('0'))
        ->assertOk()->json('data.quote_token');

    $this->actingAs($merchant->user)
        ->postJson('/api/merchant/orders', ($this->createPayload)($quote, '0'))
        ->assertCreated();

    expect((string) Order::query()->latest('id')->firstOrFail()->item_price)->toBe('0.00');
});

it('uses the profile default pickup when the request omits pickup', function () {
    $merchant = ($this->activeMerchant)([
        'default_pickup_address' => 'Default depot',
        'default_pickup_location' => Point::makeGeodetic(
            $this->world['pickup']['lat'],
            $this->world['pickup']['lng'],
        ),
    ]);

    $quote = $this->actingAs($merchant->user)->postJson('/api/merchant/orders/quote', [
        'receiver_location' => $this->world['dropoff'],
        'item_size' => ItemSize::Small->value,
        'item_price' => '20.00',
    ])->assertOk()->json('data.quote_token');

    $this->actingAs($merchant->user)->postJson('/api/merchant/orders', [
        'quote_token' => $quote,
        'item_price' => '20.00',
        'receiver_location' => $this->world['dropoff'],
        'receiver_address' => 'Customer address',
        'receiver_phone' => '+218910000222',
        'receiver_name' => 'Customer',
        'item_size' => ItemSize::Small->value,
        'item_description' => 'A box of goods',
    ])->assertCreated();

    expect(Order::query()->latest('id')->firstOrFail()->pickup_address)->toBe('Default depot');
});

it('returns 409 when the merchant override changes after the quote', function () {
    $merchant = ($this->activeMerchant)(['commission_rate_override' => '0.0500']);

    $quote = $this->actingAs($merchant->user)
        ->postJson('/api/merchant/orders/quote', ($this->quotePayload)('100.00'))
        ->assertOk()->json('data.quote_token');

    $merchant->update(['commission_rate_override' => '0.0800']);

    // Re-load the user so the create request reads the updated profile fresh
    // (production re-authenticates per request; the test reuses one instance).
    $this->actingAs($merchant->user->fresh())
        ->postJson('/api/merchant/orders', ($this->createPayload)($quote, '100.00'))
        ->assertStatus(409);
});

it('returns 400 when a token issued for another merchant is used', function () {
    $merchantA = ($this->activeMerchant)(['commission_rate_override' => '0.0500']);
    $merchantB = ($this->activeMerchant)(['commission_rate_override' => '0.0500']);

    $quote = $this->actingAs($merchantA->user)
        ->postJson('/api/merchant/orders/quote', ($this->quotePayload)('100.00'))
        ->assertOk()->json('data.quote_token');

    $this->actingAs($merchantB->user)
        ->postJson('/api/merchant/orders', ($this->createPayload)($quote, '100.00'))
        ->assertStatus(400);
});

it('blocks a suspended merchant from the order flow', function () {
    $merchant = ($this->activeMerchant)();
    $merchant->update(['status' => MerchantStatus::Suspended->value]);

    $this->actingAs($merchant->user)
        ->postJson('/api/merchant/orders/quote', ($this->quotePayload)('100.00'))
        ->assertStatus(403);
});

it('blocks a non-merchant user from the order flow', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/merchant/orders/quote', ($this->quotePayload)('100.00'))
        ->assertStatus(403);
});

it('still blocks an active merchant from the standard /api/orders flow', function () {
    $merchant = ($this->activeMerchant)();

    $quote = $this->actingAs($merchant->user)->postJson('/api/orders/quote', [
        'order_type' => OrderType::StandardDelivery->value,
        'pickup_location' => $this->world['pickup'],
        'receiver_location' => $this->world['dropoff'],
        'item_size' => ItemSize::Small->value,
    ])->assertOk()->json('data.quote_token');

    $this->actingAs($merchant->user)->postJson('/api/orders', [
        'quote_token' => $quote,
        'order_type' => OrderType::StandardDelivery->value,
        'pickup_location' => $this->world['pickup'],
        'pickup_address' => 'Shop front',
        'receiver_location' => $this->world['dropoff'],
        'receiver_address' => 'Customer address',
        'receiver_phone' => '+218910000222',
        'receiver_name' => 'Customer',
        'item_size' => ItemSize::Small->value,
        'item_description' => 'A box of goods',
        'delivery_fee_payer' => 'sender',
    ])->assertStatus(422);
});

it('lists and shows only the merchant own orders, excluding personal orders by the same user', function () {
    $merchant = ($this->activeMerchant)();

    // A merchant order.
    $quote = $this->actingAs($merchant->user)
        ->postJson('/api/merchant/orders/quote', ($this->quotePayload)('100.00'))
        ->assertOk()->json('data.quote_token');
    $this->actingAs($merchant->user)
        ->postJson('/api/merchant/orders', ($this->createPayload)($quote, '100.00'))
        ->assertCreated();
    $merchantOrder = Order::query()->latest('id')->firstOrFail();

    // A pre-merchant personal order by the same user (merchant_profile_id null).
    $personal = Order::factory()->create([
        'sender_user_id' => $merchant->user_id,
        'merchant_profile_id' => null,
    ]);

    $list = $this->actingAs($merchant->user)->getJson('/api/merchant/orders')->assertOk();
    $ids = collect($list->json('data'))->pluck('id');

    expect($ids)->toContain($merchantOrder->public_id)
        ->and($ids)->not->toContain($personal->public_id);

    $this->actingAs($merchant->user)
        ->getJson("/api/merchant/orders/{$personal->public_id}")
        ->assertNotFound();
});
