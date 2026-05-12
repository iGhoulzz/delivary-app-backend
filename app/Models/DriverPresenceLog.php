<?php

declare(strict_types=1);

namespace App\Models;

use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DriverPresenceLog extends Model
{
    public const UPDATED_AT = null; // append-only

    /** @var array<int, string> */
    protected $fillable = ['driver_id', 'event', 'reason', 'location'];

    protected function casts(): array
    {
        return [
            'location' => Point::class,
            'created_at' => 'datetime',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }
}
