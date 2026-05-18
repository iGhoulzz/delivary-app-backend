<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SellerPayoutStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

final class SellerPayout extends Model
{
    use SoftDeletes;

    /** @var array<int, string> */
    protected $fillable = [
        'public_id', 'user_id', 'amount',
        'payout_method', 'office_id',
        'status',
        'paid_at', 'paid_by_staff_id',
        'notes',
    ];

    protected static function booted(): void
    {
        self::creating(static function (SellerPayout $payout): void {
            if (empty($payout->public_id)) {
                $payout->public_id = (string) Str::ulid();
            }

            $payout->paid_at ??= now();
            $payout->status ??= SellerPayoutStatus::Paid->value;
            $payout->payout_method ??= 'cash_at_office';
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'status' => SellerPayoutStatus::class,
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(OfficeLocation::class, 'office_id');
    }

    public function paidByStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_staff_id');
    }

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(Order::class, 'seller_payout_orders', 'seller_payout_id', 'order_id')
            ->using(SellerPayoutOrder::class)
            ->withPivot(['amount_contributed'])
            ->withTimestamps();
    }

    public function earnings(): HasMany
    {
        return $this->hasMany(SellerEarning::class, 'seller_payout_id');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeAtOffice(Builder $query, int $officeId): Builder
    {
        return $query->where('office_id', $officeId);
    }
}
