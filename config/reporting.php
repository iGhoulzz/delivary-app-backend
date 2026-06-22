<?php

declare(strict_types=1);

return [
    // Operational reporting timezone (app runs in UTC). Day-bucketing for the
    // dashboard uses this so "today" matches the Libya ops day.
    'timezone' => env('REPORTING_TIMEZONE', 'Africa/Tripoli'),
];
