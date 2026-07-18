<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Testing\DatabaseTruncation;

trait TruncatesPostgisDatabase
{
    use DatabaseTruncation;

    /** @var array<int, string> */
    protected array $exceptTables = ['spatial_ref_sys'];
}
