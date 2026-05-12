<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

final class OfficeStaffAssignment extends Pivot
{
    protected $table = 'office_staff_assignments';

    public $incrementing = true;

    public $timestamps = true;

    /** @var array<int, string> */
    protected $fillable = ['user_id', 'office_id', 'is_manager', 'assigned_at', 'removed_at'];

    protected function casts(): array
    {
        return [
            'is_manager' => 'boolean',
            'assigned_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(OfficeLocation::class, 'office_id');
    }

    public function isActive(): bool
    {
        return $this->removed_at === null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('removed_at');
    }
}
