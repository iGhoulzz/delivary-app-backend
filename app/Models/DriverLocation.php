<?php

declare(strict_types=1);

namespace App\Models;

use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only history of driver GPS pings. The live "where is each driver"
 * lookup uses `driver_profiles.current_location` (overwritten in place);
 * this table is for audit, fraud detection, and analytics. Rows are pruned
 * after 7 days per spec section 9.9.
 */
final class DriverLocation extends Model
{
    public $timestamps = false;

    /** @var array<int, string> */
    protected $fillable = [
        'driver_id', 'location',
        'heading', 'speed_mps', 'accuracy_meters', 'battery_percentage',
        'recorded_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'location' => Point::class,
            'recorded_at' => 'datetime',
            'created_at' => 'datetime',
            'heading' => 'decimal:2',
            'speed_mps' => 'decimal:2',
            'accuracy_meters' => 'decimal:2',
            'battery_percentage' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::creating(static function (DriverLocation $row): void {
            $row->created_at ??= now();
        });
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function scopeForDriver(Builder $query, int $driverId): Builder
    {
        return $query->where('driver_id', $driverId);
    }

    public function scopeRecordedAfter(Builder $query, \DateTimeInterface $threshold): Builder
    {
        return $query->where('recorded_at', '>=', $threshold);
    }

    public function scopeOlderThan(Builder $query, \DateTimeInterface $threshold): Builder
    {
        return $query->where('recorded_at', '<', $threshold);
    }
}
