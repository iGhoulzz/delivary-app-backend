<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

final class SettlementOrder extends Pivot
{
    protected $table = 'settlement_orders';

    public $incrementing = true;

    public $timestamps = true;

    /** @var array<int, string> */
    protected $fillable = ['settlement_id', 'order_id', 'amount_contributed'];

    protected function casts(): array
    {
        return [
            'amount_contributed' => 'decimal:2',
        ];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
