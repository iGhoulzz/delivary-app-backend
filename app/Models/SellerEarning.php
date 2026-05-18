<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SellerEarningStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

final class SellerEarning extends Model
{
    use SoftDeletes;

    /** @var array<int, string> */
    protected $fillable = [
        'public_id', 'order_id', 'seller_user_id',
        'amount', 'status',
        'cleared_at', 'available_at', 'paid_out_at',
        'paid_by_staff_id', 'seller_payout_id',
        'notes',
    ];

    protected static function booted(): void
    {
        self::creating(static function (SellerEarning $earning): void {
            if (empty($earning->public_id)) {
                $earning->public_id = (string) Str::ulid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'status' => SellerEarningStatus::class,
            'amount' => 'decimal:2',
            'cleared_at' => 'datetime',
            'available_at' => 'datetime',
            'paid_out_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_user_id');
    }

    public function paidByStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_staff_id');
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(SellerPayout::class, 'seller_payout_id');
    }

    public function scopeForSeller(Builder $query, int $sellerId): Builder
    {
        return $query->where('seller_user_id', $sellerId);
    }

    public function scopeWithStatus(Builder $query, SellerEarningStatus ...$statuses): Builder
    {
        return $query->whereIn(
            'status',
            array_map(static fn (SellerEarningStatus $status): string => $status->value, $statuses),
        );
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', SellerEarningStatus::Available->value);
    }

    public function scopePendingClearance(Builder $query): Builder
    {
        return $query->where('status', SellerEarningStatus::PendingClearance->value);
    }

    public function scopePendingSettlementForDriver(Builder $query, int $driverId): Builder
    {
        return $query
            ->where('status', SellerEarningStatus::PendingSettlement->value)
            ->whereHas('order', fn (Builder $q): Builder => $q->where('driver_id', $driverId));
    }
}
