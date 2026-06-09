<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Hard guard: refuse to run any test against a non-testing database.
     * This must run BEFORE parent::setUp() (which triggers RefreshDatabase's
     * migrate:fresh) so an accidental `DB_DATABASE=delivary_app vendor/bin/pest`
     * can never wipe the dev database. Reads the raw env, not config().
     */
    protected function setUp(): void
    {
        $database = getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? '');

        if ($database === 'delivary_app' || ! str_starts_with((string) $database, 'delivary_app_testing')) {
            throw new RuntimeException(
                "Refusing to run tests against non-testing database: [{$database}]. ".
                'Tests must target delivary_app_testing (or a delivary_app_testing_* override).'
            );
        }

        parent::setUp();
    }
}
