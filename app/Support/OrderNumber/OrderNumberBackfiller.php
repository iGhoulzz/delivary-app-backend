<?php

declare(strict_types=1);

namespace App\Support\OrderNumber;

use Illuminate\Support\Facades\DB;

final class OrderNumberBackfiller
{
    /**
     * Assign a unique order_number to every order that has none — INCLUDING soft-deleted orders.
     * DB::table bypasses the Order SoftDeletes global scope; trashed rows still need a number (NOT NULL
     * covers them and the UNIQUE index spans them).
     */
    public static function run(): void
    {
        $generator = app(OrderNumberGenerator::class);
        DB::table('orders')->whereNull('order_number')->orderBy('id')->chunkById(500, function ($orders) use ($generator): void {
            foreach ($orders as $order) {
                DB::table('orders')->where('id', $order->id)->update(['order_number' => $generator->generate()]);
            }
        });
    }
}
