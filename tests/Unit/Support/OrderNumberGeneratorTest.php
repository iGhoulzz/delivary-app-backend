<?php

declare(strict_types=1);

use App\Support\OrderNumber\OrderNumberGenerator;

function orderNumberGen(): OrderNumberGenerator
{
    return new OrderNumberGenerator;
}

it('computes the ISO 7064 MOD 37,36 check character (known vectors)', function (string $body, string $check): void {
    expect(orderNumberGen()->checkCharacter($body))->toBe($check);
})->with([
    ['', '1'],
    ['0', '2'],
    ['00', '4'],
    ['A12425GABC1234002', 'M'],
    ['00000002', 'U'],
    ['00000005', 'O'],
    ['00000008', 'I'],
    ['00000088', 'L'],
]);

it('throws on a non-alphanumeric character in the checksum input', function (): void {
    orderNumberGen()->checkCharacter('7K3M-9Q2D');
})->throws(InvalidArgumentException::class);

it('generates ORD-XXXX-XXXX-C with a Crockford body and a valid check', function (): void {
    $number = orderNumberGen()->build();
    expect($number)->toMatch('/^ORD-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-Z]$/');
    expect(orderNumberGen()->isValid($number))->toBeTrue();
});

it('rejects a corrupted check character', function (): void {
    $number = orderNumberGen()->build();
    $bad = substr($number, 0, -1).(str_ends_with($number, '0') ? '1' : '0');
    expect(orderNumberGen()->isValid($bad))->toBeFalse();
});

it('aliases I/L to 1 and O to 0 in the body, rejects U, and treats the check char literally', function (): void {
    expect(orderNumberGen()->isValid('ORD-OOOO-OOO2-U'))->toBeTrue();
    $check = orderNumberGen()->checkCharacter('11111111');
    expect(orderNumberGen()->isValid("ORD-IIII-IIII-{$check}"))->toBeTrue();
    // 'V' is the checksum-correct check char for the body 'UUUUUUUU', so only the U-guard can
    // reject this. With a mismatched check char the assertion would pass even without the guard.
    expect(orderNumberGen()->isValid('ORD-UUUU-UUUU-V'))->toBeFalse();
    // checkCharacter('00000005') is 'O', so a '0' check char must NOT be Crockford-normalized.
    expect(orderNumberGen()->isValid('ORD-0000-0005-0'))->toBeFalse();
});

it('accepts surrounding whitespace and lower-case input', function (): void {
    $number = orderNumberGen()->build();
    expect(orderNumberGen()->isValid('  '.strtolower($number).'  '))->toBeTrue();
});

it('normalizes search terms: dashless, case-insensitive, partial (body only)', function (): void {
    expect(orderNumberGen()->normalizeSearchTerm('ord-7k3m-9q2d-8'))->toBe('ORD7K3M9Q2D8');
    expect(orderNumberGen()->normalizeSearchTerm('7K3M 9Q2D'))->toBe('7K3M9Q2D');
    expect(orderNumberGen()->normalizeSearchTerm('7k3m'))->toBe('7K3M');
    expect(orderNumberGen()->normalizeSearchTerm('---'))->toBe('');
});
