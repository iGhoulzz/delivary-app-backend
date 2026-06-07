<?php

declare(strict_types=1);

use App\Enums\ItemSize;
use App\Enums\OrderType;
use App\Services\Order\QuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestWorld;

uses(RefreshDatabase::class);

it('builds an active region a quote can be produced against', function (): void {
    $world = TestWorld::create();

    $quote = app(QuoteService::class)->quote(
        OrderType::StandardDelivery,
        $world['pickup']['lat'], $world['pickup']['lng'],
        $world['dropoff']['lat'], $world['dropoff']['lng'],
        ItemSize::Small, '0.00', 'sender',
    );

    expect($quote)->toHaveKey('quote_token');
});
