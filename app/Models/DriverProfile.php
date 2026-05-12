<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DriverActivityStatus;
use App\Enums\DriverStatus;
use App\Enums\VehicleType;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

final class DriverProfile extends Model
{
    use SoftDeletes;

    /** @var array<int, string> */
    protected $fillable = [
        'user_id', 'office_id',
        'status', 'approved_at', 'approved_by_admin_id',
        'rejected_at', 'rejection_reason',
        'vehicle_type', 'vehicle_plate', 'vehicle_color', 'vehicle_model',
        'activity_status', 'current_location',
        'last_location_updated_at', 'last_active_at',
        'lifetime_deliveries', 'rating_average', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => DriverStatus::class,
            'activity_status' => DriverActivityStatus::class,
            'vehicle_type' => VehicleType::class,
            'current_location' => Point::class,
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'last_location_updated_at' => 'datetime',
            'last_active_at' => 'datetime',
            'lifetime_deliveries' => 'integer',
            'rating_average' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The office where the driver was face-to-face onboarded.
     */
    public function office(): BelongsTo
    {
        return $this->belongsTo(OfficeLocation::class, 'office_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_admin_id');
    }

    /**
     * The driver's three-bucket account. Joined through the shared user_id
     * since both `driver_profiles` and `driver_accounts` reference the user.
     */
    public function account(): HasOne
    {
        return $this->hasOne(DriverAccount::class, 'driver_id', 'user_id');
    }

    public function strikes(): HasMany
    {
        return $this->hasMany(DriverStrike::class, 'driver_id', 'user_id');
    }

    public function locationHistory(): HasMany
    {
        return $this->hasMany(DriverLocation::class, 'driver_id', 'user_id');
    }

    /**
     * Orders this driver has been assigned to (current and historical).
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'driver_id', 'user_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class, 'driver_id', 'user_id');
    }

    public function canAcceptOrders(): bool
    {
        return $this->status->canAcceptOrders()
            && $this->activity_status->isAvailableForBroadcast();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', DriverStatus::Active->value);
    }

    public function scopeOnline(Builder $query): Builder
    {
        return $query->where('activity_status', DriverActivityStatus::Online->value);
    }

    /**
     * Drivers within `radiusMeters` of the given pickup point.
     * Ordered by ascending distance for deterministic broadcast.
     */
    public function scopeWithinRadiusOf(Builder $query, float $latitude, float $longitude, int $radiusMeters): Builder
    {
        return $query
            ->whereRaw(
                'ST_DWithin(current_location, ST_MakePoint(?, ?)::geography, ?)',
                [$longitude, $latitude, $radiusMeters]
            )
            ->orderByRaw(
                'ST_Distance(current_location, ST_MakePoint(?, ?)::geography) ASC',
                [$longitude, $latitude]
            );
    }

    public function scopeWithVehicleTypes(Builder $query, VehicleType ...$types): Builder
    {
        return $query->whereIn('vehicle_type', array_map(static fn (VehicleType $t): string => $t->value, $types));
    }
}
