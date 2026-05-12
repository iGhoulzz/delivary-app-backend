<?php

declare(strict_types=1);

namespace App\Models;

use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

final class OfficeLocation extends Model
{
    use SoftDeletes;

    /** @var array<int, string> */
    protected $fillable = [
        'public_id', 'region_id', 'name', 'address', 'location',
        'phone', 'operating_hours', 'is_active', 'capacity', 'manager_user_id',
    ];

    protected static function booted(): void
    {
        self::creating(static function (OfficeLocation $office): void {
            if (empty($office->public_id)) {
                $office->public_id = (string) Str::ulid();
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
            'location' => Point::class,
            'operating_hours' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /**
     * Regions that designate this office as their operational office.
     */
    public function assignedRegions(): HasMany
    {
        return $this->hasMany(Region::class, 'office_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    public function staffAssignments(): HasMany
    {
        return $this->hasMany(OfficeStaffAssignment::class, 'office_id');
    }

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'office_staff_assignments', 'office_id', 'user_id')
            ->using(OfficeStaffAssignment::class)
            ->withPivot(['is_manager', 'assigned_at', 'removed_at'])
            ->withTimestamps();
    }

    /**
     * Drivers registered at this office (face-to-face onboarding location).
     */
    public function registeredDrivers(): HasMany
    {
        return $this->hasMany(DriverProfile::class, 'office_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Orders that have been returned to this office.
     */
    public function returnedOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'return_office_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class, 'office_id');
    }

    public function inventory(): HasMany
    {
        return $this->hasMany(OfficeInventory::class, 'office_id');
    }
}
