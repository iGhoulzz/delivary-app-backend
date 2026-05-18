<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

final class OfficeInventory extends Model
{
    use SoftDeletes;

    protected $table = 'office_inventory';

    /** @var array<int, string> */
    protected $fillable = [
        'public_id', 'order_id', 'office_id',
        'received_by_staff_id', 'received_at', 'shelf_location',
        'accrued_storage_fee', 'last_fee_accrued_on',
        'retrieved_at', 'retrieved_by_staff_id',
        'cash_collected_at_retrieval', 'retrieval_fees_waived_amount',
        'abandoned_at', 'abandoned_by_admin_id', 'disposal_notes',
        'notes',
    ];

    protected static function booted(): void
    {
        self::creating(static function (OfficeInventory $i): void {
            if (empty($i->public_id)) {
                $i->public_id = (string) Str::ulid();
            }
            $i->received_at ??= now();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'retrieved_at' => 'datetime',
            'abandoned_at' => 'datetime',
            'last_fee_accrued_on' => 'date',
            'accrued_storage_fee' => 'decimal:2',
            'cash_collected_at_retrieval' => 'decimal:2',
            'retrieval_fees_waived_amount' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(OfficeLocation::class, 'office_id');
    }

    public function receivedByStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_staff_id');
    }

    public function retrievedByStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retrieved_by_staff_id');
    }

    public function abandonedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'abandoned_by_admin_id');
    }

    public function isInStock(): bool
    {
        return $this->retrieved_at === null && $this->abandoned_at === null;
    }

    public function isRetrieved(): bool
    {
        return $this->retrieved_at !== null;
    }

    public function isAbandoned(): bool
    {
        return $this->abandoned_at !== null;
    }

    public function daysInStock(): int
    {
        return (int) $this->received_at->diffInDays(now());
    }

    public function scopeAtOffice(Builder $query, int $officeId): Builder
    {
        return $query->where('office_id', $officeId);
    }

    public function scopeInStock(Builder $query): Builder
    {
        return $query->whereNull('retrieved_at')->whereNull('abandoned_at');
    }

    public function scopeRetrieved(Builder $query): Builder
    {
        return $query->whereNotNull('retrieved_at');
    }

    public function scopeAbandoned(Builder $query): Builder
    {
        return $query->whereNotNull('abandoned_at');
    }
}
