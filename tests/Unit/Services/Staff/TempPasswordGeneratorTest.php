<?php

declare(strict_types=1);

use App\Services\Staff\TempPasswordGenerator;

it('generates a 10-character alphanumeric password', function (): void {
    $password = (new TempPasswordGenerator)->generate();

    expect($password)->toBeString();
    expect(strlen($password))->toBe(10);
    expect($password)->toMatch('/^[A-Za-z0-9]+$/');
});

it('produces a different password on each call', function (): void {
    $gen = new TempPasswordGenerator;
    $passwords = collect(range(1, 50))->map(fn () => $gen->generate());
    expect($passwords->unique()->count())->toBe(50);
});
