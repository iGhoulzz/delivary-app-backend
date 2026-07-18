<?php

declare(strict_types=1);

namespace App\Support\OrderNumber;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;

final class OrderNumberRetry
{
    /** The unique index guarding order_number; the only violation we retry. */
    public const INDEX = 'orders_order_number_unique';

    /**
     * Run $tx; if it fails with the orders_order_number_unique violation, re-run it (a fresh run
     * regenerates order_number via the Order creating hook) up to $attempts times.
     *
     * The whole transaction must be re-run rather than retried inside: Postgres aborts the
     * transaction on the violation, and DB::transaction()'s own retry only covers deadlocks.
     *
     * The violated index is matched EXACTLY against $e->index (populated by the driver's
     * parseUniqueConstraintViolation) rather than by substring-matching getMessage(): the message
     * interpolates the query bindings, which carry user-controlled free text (item_description,
     * notes, names), so a violation of a different unique constraint could smuggle this index name
     * into the message and win a wrong retry. An exact match is also rename-safe (..._unique_v2).
     */
    public static function run(Closure $tx, int $attempts = 3): mixed
    {
        for ($i = 1; ; $i++) {
            try {
                return $tx();
            } catch (UniqueConstraintViolationException $e) {
                if ($i >= $attempts || $e->index !== self::INDEX) {
                    throw $e; // exhausted, or a different unique constraint → not ours to retry
                }
            }
        }
    }
}
