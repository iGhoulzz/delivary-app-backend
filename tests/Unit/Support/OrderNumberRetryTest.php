<?php

declare(strict_types=1);

use App\Support\OrderNumber\OrderNumberRetry;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Mirrors what Illuminate\Database\Connection does on a 23505: it constructs the exception and
 * then calls setIndex() with the name parsed out by PostgresConnection. Constructing via `new`
 * alone would leave $index null and make the retry gate pass vacuously.
 */
function orderNumberViolation(string $constraint): UniqueConstraintViolationException
{
    return (new UniqueConstraintViolationException(
        'pgsql',
        'insert into "orders" (...) values (...)',
        [],
        new PDOException("SQLSTATE[23505]: unique_violation: duplicate key value violates unique constraint \"{$constraint}\""),
    ))->setIndex($constraint);
}

it('builds a violation whose index is populated (guards the gate against vacuity)', function (): void {
    expect(orderNumberViolation('orders_order_number_unique')->index)->toBe('orders_order_number_unique');
});

it('retries an order_number unique violation once, then succeeds', function (): void {
    $calls = 0;
    $result = OrderNumberRetry::run(function () use (&$calls) {
        $calls++;
        if ($calls === 1) {
            throw orderNumberViolation('orders_order_number_unique');
        }

        return 'ok';
    });

    expect($result)->toBe('ok');
    expect($calls)->toBe(2);
});

it('rethrows a non-order_number unique violation without retrying', function (): void {
    $calls = 0;
    expect(function () use (&$calls) {
        OrderNumberRetry::run(function () use (&$calls) {
            $calls++;
            throw orderNumberViolation('orders_public_id_unique');
        });
    })->toThrow(UniqueConstraintViolationException::class);
    expect($calls)->toBe(1);
});

it('gives up after the attempt cap', function (): void {
    $calls = 0;
    expect(function () use (&$calls) {
        OrderNumberRetry::run(function () use (&$calls) {
            $calls++;
            throw orderNumberViolation('orders_order_number_unique');
        }, 3);
    })->toThrow(UniqueConstraintViolationException::class);
    expect($calls)->toBe(3);
});

it('does not catch exceptions other than a unique-constraint violation', function (): void {
    $calls = 0;
    expect(function () use (&$calls) {
        OrderNumberRetry::run(function () use (&$calls) {
            $calls++;
            throw new RuntimeException('boom');
        });
    })->toThrow(RuntimeException::class);
    expect($calls)->toBe(1);
});

it('returns the closure result without retrying when it succeeds', function (): void {
    $calls = 0;
    $result = OrderNumberRetry::run(function () use (&$calls) {
        $calls++;

        return 'first-try';
    });

    expect($result)->toBe('first-try');
    expect($calls)->toBe(1);
});
