<?php

declare(strict_types=1);

return [
    'settlement' => [
        'excess_rejected' => 'Cash received exceeds amount owed. Hand the excess back to the driver before submitting.',
        'empty' => 'Driver has no balances to settle.',
        'cash_mismatch' => 'Cash counts do not reconcile against the driver account.',
        'not_reversible' => 'Settlement can no longer be reversed.',
        'already_reversed' => 'Settlement has already been reversed.',
    ],
    'payout' => [
        'earning_not_available' => 'One or more selected earnings are not available for payout.',
        'earning_wrong_seller' => 'Selected earnings do not all belong to this seller.',
        'total_mismatch' => 'Submitted total does not match the sum of selected earnings.',
        'below_minimum' => 'Selected total is below the minimum payout amount.',
        'empty_selection' => 'Select at least one earning to pay out.',
    ],
    'office' => [
        'not_assigned' => 'You are not assigned to any active office.',
    ],
    'seller' => [
        'not_found' => 'Seller not found.',
    ],
    'driver' => [
        'not_found' => 'Driver not found.',
    ],
];
