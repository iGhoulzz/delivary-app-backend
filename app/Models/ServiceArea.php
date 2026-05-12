<?php

declare(strict_types=1);

namespace App\Models;

use Clickbar\Magellan\Data\Geometries\Polygon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ServiceArea extends Model
{
    /**
     * @use Fillable
     *
     * @var array<int, string>
     */
    protected $fillable = ['name', 'boundary', 'is_active'];

    protected function casts(): array
    {
        return [
            'boundary' => Polygon::class,
            'is_active' => 'boolean',
        ];
    }

    public function regions(): HasMany
    {
        return $this->hasMany(Region::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Service areas whose boundary contains the given lat/lng point.
     */
    public function scopeContaining(Builder $query, float $latitude, float $longitude): Builder
    {
        return $query->whereRaw(
            'ST_Contains(boundary::geometry, ST_SetSRID(ST_MakePoint(?, ?), 4326))',
            [$longitude, $latitude]
        );
    }
}
