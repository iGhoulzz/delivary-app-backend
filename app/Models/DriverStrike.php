<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DriverStrikeIssuer;
use App\Enums\DriverStrikeReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class DriverStrike extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'public_id',
        'driver_id', 'order_id',
        'reason', 'fee_amount',
        'issued_by', 'issued_by_admin_id',
        'is_voided', 'voided_at', 'voided_by_admin_id', 'void_reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'reason' => DriverStrikeReason::class,
            'issued_by' => DriverStrikeIssuer::class,
            'fee_amount' => 'decimal:2',
            'is_voided' => 'boolean',
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $strike): void {
            $strike->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function issuedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_admin_id');
    }

    public function voidedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_admin_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isActive(): bool
    {
        return ! $this->is_voided;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_voided', false);
    }

    public function scopeForDriver(Builder $query, int $driverId): Builder
    {
        return $query->where('driver_id', $driverId);
    }

    /**
     * Strikes issued in the trailing window (default 30 days).
     * Used by the system to determine review/suspension thresholds
     * (3 strikes / 5 strikes — both configurable in platform_settings).
     */
    public function scopeIssuedInLastDays(Builder $query, int $days = 30): Builder
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}
