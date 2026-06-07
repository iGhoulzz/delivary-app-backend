<?php

declare(strict_types=1);

it('never runs tests against the dev database', function (): void {
    $db = config('database.connections.pgsql.database');

    expect($db)->not->toBe('delivary_app');            // never the dev DB
    expect($db)->toStartWith('delivary_app_testing');  // always a test DB
});
