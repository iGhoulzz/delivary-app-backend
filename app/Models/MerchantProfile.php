<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MerchantStatus;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

final class MerchantProfile extends Model
{
    use HasFactory;
    use SoftDeletes;

    /** @var array<int, string> */
    protected $fillable = [
        'public_id', 'user_id',
        'business_name', 'business_phone',
        'status', 'created_by_admin_id',
        'approved_at', 'approved_by_admin_id',
        'commission_rate_override', 'driver_fee_cut_override',
        'default_pickup_address', 'default_pickup_location',
        'notes',
    ];

    protected static function booted(): void
    {
        self::creating(static function (MerchantProfile $merchant): void {
            if (empty($merchant->public_id)) {
                $merchant->public_id = (string) Str::ulid();
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
            'status' => MerchantStatus::class,
            'default_pickup_location' => Point::class,
            'approved_at' => 'datetime',
            'commission_rate_override' => 'decimal:4',
            'driver_fee_cut_override' => 'decimal:4',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_admin_id');
    }

    /**
     * The phone number to display to drivers and customers — falls back to the
     * owner's personal phone if no business phone is configured.
     */
    public function contactPhone(): ?string
    {
        return $this->business_phone ?? $this->user?->phone_number;
    }

    public function canCreateOrders(): bool
    {
        return $this->status->canCreateOrders();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', MerchantStatus::Active->value);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'merchant_profile_id');
    }
}
