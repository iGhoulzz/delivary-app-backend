<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

final class GuestRecipient extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'public_id', 'phone_number',
        'first_name', 'last_name',
        'first_received_at', 'last_received_at',
        'total_deliveries',
        'converted_to_user_id', 'converted_at',
    ];

    protected static function booted(): void
    {
        self::creating(static function (GuestRecipient $guest): void {
            if (empty($guest->public_id)) {
                $guest->public_id = (string) Str::ulid();
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
            'first_received_at' => 'datetime',
            'last_received_at' => 'datetime',
            'converted_at' => 'datetime',
            'total_deliveries' => 'integer',
        ];
    }

    public function convertedToUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_to_user_id');
    }

    public function fullName(): ?string
    {
        $name = trim(($this->first_name ?? '').' '.($this->last_name ?? ''));

        return $name === '' ? null : $name;
    }

    public function isConverted(): bool
    {
        return $this->converted_to_user_id !== null;
    }

    public function scopeUnconverted(Builder $query): Builder
    {
        return $query->whereNull('converted_to_user_id');
    }

    public function scopeForPhone(Builder $query, string $phoneNumber): Builder
    {
        return $query->where('phone_number', $phoneNumber);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'receiver_guest_id');
    }
}
