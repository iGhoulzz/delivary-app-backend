<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethodProvider;
use App\Enums\PaymentMethodType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A user's saved tokenised payment instrument (Group 9 — architecture-ready,
 * inactive at MVP). The platform only stores an opaque gateway-issued token
 * plus display-safe metadata (brand, last four, etc.). Raw card data never
 * touches our database.
 */
final class PaymentMethod extends Model
{
    use SoftDeletes;

    /** @var array<int, string> */
    protected $fillable = [
        'public_id',
        'user_id',
        'provider',
        'type',
        'gateway_token',
        'card_brand',
        'card_last_four',
        'card_holder_name',
        'expiry_month',
        'expiry_year',
        'is_default',
        'is_active',
        'gateway_metadata',
    ];

    protected $hidden = [
        // Never serialise the encrypted token in API responses. Reads through
        // the model still decrypt transparently for server-side use.
        'gateway_token',
    ];

    protected static function booted(): void
    {
        self::creating(static function (PaymentMethod $pm): void {
            if (empty($pm->public_id)) {
                $pm->public_id = (string) Str::ulid();
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
            'provider' => PaymentMethodProvider::class,
            'type' => PaymentMethodType::class,
            'gateway_token' => 'encrypted',
            'gateway_metadata' => 'array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'expiry_month' => 'integer',
            'expiry_year' => 'integer',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function topupRequests(): HasMany
    {
        return $this->hasMany(TopupRequest::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Predicates
    |--------------------------------------------------------------------------
    */

    /**
     * True if the saved card's expiry month/year are in the past relative to
     * the given moment (defaults to "now"). Cards without expiry data (e.g.
     * future non-card method types) are never considered expired by this check.
     */
    public function isExpired(?\DateTimeInterface $asOf = null): bool
    {
        if ($this->expiry_month === null || $this->expiry_year === null) {
            return false;
        }

        $asOf ??= now();
        $asOfYear = (int) $asOf->format('Y');
        $asOfMonth = (int) $asOf->format('n');

        return $this->expiry_year < $asOfYear
            || ($this->expiry_year === $asOfYear && $this->expiry_month < $asOfMonth);
    }

    public function isUsable(): bool
    {
        return $this->is_active && ! $this->isExpired();
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }
}
