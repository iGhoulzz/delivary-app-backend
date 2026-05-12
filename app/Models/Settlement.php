<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SettlementStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

final class Settlement extends Model
{
    use SoftDeletes;

    /** @var array<int, string> */
    protected $fillable = [
        'public_id', 'driver_id', 'office_id', 'processed_by_staff_id',
        'cash_received_from_driver', 'cash_paid_to_driver',
        'cash_to_deposit_cleared', 'earnings_balance_cleared', 'debt_balance_cleared',
        'shortage_amount', 'excess_amount',
        'status', 'notes',
    ];

    protected static function booted(): void
    {
        self::creating(static function (Settlement $s): void {
            if (empty($s->public_id)) {
                $s->public_id = (string) Str::ulid();
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
            'status' => SettlementStatus::class,
            'cash_received_from_driver' => 'decimal:2',
            'cash_paid_to_driver' => 'decimal:2',
            'cash_to_deposit_cleared' => 'decimal:2',
            'earnings_balance_cleared' => 'decimal:2',
            'debt_balance_cleared' => 'decimal:2',
            'shortage_amount' => 'decimal:2',
            'excess_amount' => 'decimal:2',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(OfficeLocation::class, 'office_id');
    }

    public function processedByStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by_staff_id');
    }

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(Order::class, 'settlement_orders', 'settlement_id', 'order_id')
            ->using(SettlementOrder::class)
            ->withPivot(['amount_contributed'])
            ->withTimestamps();
    }

    /**
     * The signed cash flow at this settlement:
     *   positive = driver handed cash to platform
     *   negative = platform paid cash to driver
     *   zero     = no cash changed hands (balances cancelled out exactly)
     */
    public function cashMovement(): string
    {
        return bcsub(
            (string) $this->cash_received_from_driver,
            (string) $this->cash_paid_to_driver,
            2
        );
    }

    public function scopeForDriver(Builder $query, int $driverId): Builder
    {
        return $query->where('driver_id', $driverId);
    }

    public function scopeAtOffice(Builder $query, int $officeId): Builder
    {
        return $query->where('office_id', $officeId);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', SettlementStatus::Completed->value);
    }
}
