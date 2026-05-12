<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethodProvider;
use App\Enums\TopupRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A wallet top-up request lifecycle row (Group 9 — architecture-ready,
 * inactive at MVP). Captures the full audit trail from `pending` through
 * gateway interaction to terminal state, plus a soft pointer to the eventual
 * Bavix wallet credit transaction for reconciliation.
 *
 * Completed rows are immutable in business logic: corrections are issued via
 * a new compensating record, never by mutating history.
 */
final class TopupRequest extends Model
{
    use SoftDeletes;

    /** @var array<int, string> */
    protected $fillable = [
        'public_id',
        'user_id',
        'payment_method_id',
        'amount',
        'status',
        'gateway_provider',
        'gateway_transaction_id',
        'gateway_reference',
        'wallet_transaction_id',
        'gateway_response',
        'failure_reason',
        'requested_at',
        'processed_at',
        'completed_at',
        'failed_at',
        'cancelled_at',
        'refunded_at',
    ];

    protected static function booted(): void
    {
        self::creating(static function (TopupRequest $tr): void {
            if (empty($tr->public_id)) {
                $tr->public_id = (string) Str::ulid();
            }
            $tr->requested_at ??= now();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'status' => TopupRequestStatus::class,
            'gateway_provider' => PaymentMethodProvider::class,
            'amount' => 'decimal:2',
            'gateway_response' => 'array',
            'requested_at' => 'datetime',
            'processed_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'refunded_at' => 'datetime',
            'wallet_transaction_id' => 'integer',
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

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Predicates
    |--------------------------------------------------------------------------
    */

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function isSuccessful(): bool
    {
        return $this->status->isSuccessful();
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

    public function scopeWithStatus(Builder $query, TopupRequestStatus ...$statuses): Builder
    {
        return $query->whereIn('status', array_map(
            static fn (TopupRequestStatus $s): string => $s->value,
            $statuses,
        ));
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            TopupRequestStatus::Pending->value,
            TopupRequestStatus::Processing->value,
        ]);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', TopupRequestStatus::Completed->value);
    }
}
