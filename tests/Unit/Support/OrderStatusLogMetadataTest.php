<?php

declare(strict_types=1);

use App\Support\OrderStatusLogMetadata;

it('drops unknown *_id keys and keeps allowlisted keys', function (): void {
    $clean = OrderStatusLogMetadata::sanitize([
        'previous_office_public_id' => '01ABC',
        'new_office_public_id' => '01DEF',
        'reason_note' => 'redirected',
        'previous_office_id' => 42,        // internal — must be dropped
        'some_other_id' => 7,              // unknown *_id — must be dropped
    ]);

    expect($clean)->toBe([
        'previous_office_public_id' => '01ABC',
        'new_office_public_id' => '01DEF',
        'reason_note' => 'redirected',
    ]);
});

it('returns an empty array for null metadata', function (): void {
    expect(OrderStatusLogMetadata::sanitize(null))->toBe([]);
});

it('keeps the descriptive audit keys actually written by status-log writers', function (): void {
    $clean = OrderStatusLogMetadata::sanitize([
        'event' => 'return_office_redirected',
        'return_office_public_id' => '01OFFICE',
        'previous_office_public_id' => '01PREV',
        'new_office_public_id' => '01NEW',
        'driver_public_id' => '01DRIVER',
        'cancelled_by_public_id' => '01USER',
        'shelf_location' => 'A-12',
        'reset_tier' => true,
        'driver_fault' => false,
        'force' => true,
    ]);

    expect($clean)->toBe([
        'event' => 'return_office_redirected',
        'return_office_public_id' => '01OFFICE',
        'previous_office_public_id' => '01PREV',
        'new_office_public_id' => '01NEW',
        'driver_public_id' => '01DRIVER',
        'cancelled_by_public_id' => '01USER',
        'shelf_location' => 'A-12',
        'reset_tier' => true,
        'driver_fault' => false,
        'force' => true,
    ]);
});

it('fails closed: a non-allowlisted internal-id-shaped key is dropped', function (): void {
    $clean = OrderStatusLogMetadata::sanitize([
        'cancelled_by_user_id' => 99,  // not allowlisted AND internal-id-shaped
        'region_id' => 3,              // internal id of a non-public model
        'inventory_id' => 7,           // internal id
        'event' => 'order_cancelled',
    ]);

    expect($clean)->toBe(['event' => 'order_cancelled']);
});
