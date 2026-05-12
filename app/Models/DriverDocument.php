<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DriverDocumentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DriverDocument extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'driver_id', 'document_type',
        'verified', 'verified_by_admin_id', 'verified_at',
        'expires_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => DriverDocumentType::class,
            'verified' => 'boolean',
            'verified_at' => 'datetime',
            'expires_at' => 'date',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_admin_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('verified', true);
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('expires_at')->where('expires_at', '<', now()->toDateString());
    }
}
