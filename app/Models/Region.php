<?php

declare(strict_types=1);

namespace App\Models;

use Clickbar\Magellan\Data\Geometries\Polygon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Region extends Model
{
    /** @var array<int, string> */
    protected $fillable = ['service_area_id', 'office_id', 'name', 'boundary', 'is_active', 'base_fee'];

    protected function casts(): array
    {
        return [
            'boundary' => Polygon::class,
            'is_active' => 'boolean',
            'base_fee' => 'decimal:2',
        ];
    }

    public function serviceArea(): BelongsTo
    {
        return $this->belongsTo(ServiceArea::class);
    }

    /**
     * The office this region is administratively tied to (where staff process
     * its returns and settlements). Nullable; resolved via a separate FK to
     * avoid the regions ↔ office_locations circular dependency.
     */
    public function office(): BelongsTo
    {
        return $this->belongsTo(OfficeLocation::class, 'office_id');
    }

    /**
     * Office locations whose physical address falls inside this region.
     */
    public function officeLocations(): HasMany
    {
        return $this->hasMany(OfficeLocation::class);
    }

    /**
     * Drivers assigned to operate in this region.
     */
    public function drivers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'driver_region', 'region_id', 'driver_id')
            ->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Regions whose boundary contains the given lat/lng point.
     */
    public function scopeContaining(Builder $query, float $latitude, float $longitude): Builder
    {
        return $query->whereRaw(
            'ST_Contains(boundary::geometry, ST_SetSRID(ST_MakePoint(?, ?), 4326))',
            [$longitude, $latitude]
        );
    }
}
