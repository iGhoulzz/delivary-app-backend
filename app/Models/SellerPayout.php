<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SellerPayoutMethod;
use App\Enums\SellerPayoutStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

final class SellerPayout extends Model
{
    use SoftDeletes;

    /** @var array<int, string> */
    protected $fillable = [
        'public_id', 'user_id', 'amount',
        'payout_method', 'office_id',
        'status', 'requested_at',
        'approved_at', 'approved_by_admin_id',
        'paid_at', 'paid_by_admin_id',
        'rejected_at', 'rejected_by_admin_id', 'rejection_reason',
        'notes',
    ];

    protected static function booted(): void
    {
        self::creating(static function (SellerPayout $p): void {
            if (empty($p->public_id)) {
                $p->public_id = (string) Str::ulid();
            }
            $p->requested_at ??= now();
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
            'payout_method' => SellerPayoutMethod::class,
            'amount' => 'decimal:2',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'rejected_at' => 'datetime',
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

    public function approvedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_admin_id');
    }

    public function paidByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_admin_id');
    }

    public function rejectedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_admin_id');
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeWithStatus(Builder $query, SellerPayoutStatus ...$statuses): Builder
    {
        return $query->whereIn('status', array_map(static fn (SellerPayoutStatus $s): string => $s->value, $statuses));
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            SellerPayoutStatus::Pending->value,
            SellerPayoutStatus::Approved->value,
        ]);
    }
}
