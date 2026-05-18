<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

final class SellerPayoutOrder extends Pivot
{
    public $incrementing = true;

    protected $table = 'seller_payout_orders';

    /** @var array<int, string> */
    protected $fillable = [
        'seller_payout_id', 'order_id', 'amount_contributed',
    ];

    protected function casts(): array
    {
        return [
            'amount_contributed' => 'decimal:2',
        ];
    }
}
