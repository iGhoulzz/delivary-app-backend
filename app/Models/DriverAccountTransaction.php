<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DriverAccountBucket;
use App\Enums\DriverAccountTransactionReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class DriverAccountTransaction extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'driver_id', 'bucket', 'amount', 'reason',
        'reference_type', 'reference_id',
        'balance_after', 'notes', 'created_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'bucket' => DriverAccountBucket::class,
            'reason' => DriverAccountTransactionReason::class,
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin_id');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function isCredit(): bool
    {
        return bccomp((string) $this->amount, '0', 2) > 0;
    }

    public function isDebit(): bool
    {
        return bccomp((string) $this->amount, '0', 2) < 0;
    }

    public function scopeForBucket(Builder $query, DriverAccountBucket $bucket): Builder
    {
        return $query->where('bucket', $bucket->value);
    }

    public function scopeForDriver(Builder $query, int $driverId): Builder
    {
        return $query->where('driver_id', $driverId);
    }
}
