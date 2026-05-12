<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderActorType;
use App\Enums\OrderStatus;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit trail of every order status transition.
 * Powers dispute resolution. Rows are NEVER updated or deleted.
 */
final class OrderStatusLog extends Model
{
    public const UPDATED_AT = null; // append-only — no updated_at column either

    /** @var array<int, string> */
    protected $fillable = [
        'order_id',
        'from_status', 'to_status',
        'actor_type', 'actor_id',
        'reason', 'notes',
        'actor_location', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => OrderStatus::class,
            'to_status' => OrderStatus::class,
            'actor_type' => OrderActorType::class,
            'actor_location' => Point::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The acting user, when actor_type is one of the user-bound roles
     * (user, driver, admin, office_staff). Null for `system` actors.
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function scopeForOrder(Builder $query, int $orderId): Builder
    {
        return $query->where('order_id', $orderId);
    }
}
