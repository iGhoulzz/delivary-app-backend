<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeliveryFeePayer;
use App\Enums\DeliveryFeePaymentMethod;
use App\Enums\DeliveryFeeStatus;
use App\Enums\DeliveryMethod;
use App\Enums\ItemSize;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PickupMethod;
use App\Enums\ReceiverType;
use App\Enums\ReturnFault;
use App\Enums\ReturnReason;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

final class Order extends Model
{
    use HasFactory;
    use SoftDeletes;

    /** @var array<int, string> */
    protected $fillable = [
        // identity
        'public_id', 'tracking_token',
        // type & status
        'order_type', 'status', 'status_changed_at',
        // sender
        'sender_user_id', 'sender_phone', 'sender_name',
        // pickup
        'pickup_address', 'pickup_location', 'pickup_notes',
        'pickup_code', 'pickup_code_attempts', 'picked_up_method',
        'pickup_geofence_confirmed_at',
        // receiver
        'receiver_type', 'receiver_user_id', 'receiver_guest_id',
        'receiver_phone', 'receiver_name', 'receiver_address',
        'receiver_location', 'receiver_notes',
        'delivery_code', 'delivery_code_attempts', 'delivered_method',
        // merchant
        'merchant_profile_id',
        // driver
        'driver_id', 'driver_assignment_attempts', 'search_radius_tier',
        // item
        'item_description', 'item_size', 'item_weight_kg', 'item_value',
        // financial snapshots
        'item_price',
        'commission_rate', 'commission_amount',
        'delivery_fee_base', 'delivery_fee_surcharge_percent', 'delivery_fee',
        'driver_fee_cut_rate', 'driver_fee_cut_amount',
        'delivery_fee_payer', 'delivery_fee_payment_method',
        'delivery_fee_status', 'delivery_fee_paid_at',
        // future-ready
        'tip_amount', 'discount_amount', 'discount_type', 'scheduled_for',
        // returns
        'return_office_id', 'return_reason', 'return_fault',
        'returned_to_office_at', 'retrieved_by_seller_at', 'abandoned_at',
        'storage_fee_accrued',
        // cancellation
        'cancelled_at', 'cancelled_by_user_id', 'cancellation_reason', 'cancellation_fee',
        // status timestamps
        'awaiting_driver_at', 'no_driver_available_at', 'assigned_at',
        'driver_en_route_pickup_at', 'picked_up_at', 'driver_en_route_dropoff_at',
        'delivery_in_progress_at', 'delivered_at', 'delivery_failed_at',
        'returning_to_office_at', 'at_office_at',
    ];

    protected static function booted(): void
    {
        self::creating(static function (Order $order): void {
            if (empty($order->public_id)) {
                $order->public_id = (string) Str::ulid();
            }
            if (empty($order->tracking_token)) {
                $order->tracking_token = (string) Str::ulid();
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
            // enums
            'order_type' => OrderType::class,
            'status' => OrderStatus::class,
            'item_size' => ItemSize::class,
            'receiver_type' => ReceiverType::class,
            'picked_up_method' => PickupMethod::class,
            'delivered_method' => DeliveryMethod::class,
            // encrypted codes
            'pickup_code' => 'encrypted',
            'delivery_code' => 'encrypted',
            'delivery_fee_payer' => DeliveryFeePayer::class,
            'delivery_fee_payment_method' => DeliveryFeePaymentMethod::class,
            'delivery_fee_status' => DeliveryFeeStatus::class,
            'return_reason' => ReturnReason::class,
            'return_fault' => ReturnFault::class,
            // geography
            'pickup_location' => Point::class,
            'receiver_location' => Point::class,
            // money
            'item_price' => 'decimal:2',
            'commission_rate' => 'decimal:4',
            'commission_amount' => 'decimal:2',
            'delivery_fee_base' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'driver_fee_cut_rate' => 'decimal:4',
            'driver_fee_cut_amount' => 'decimal:2',
            'tip_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'storage_fee_accrued' => 'decimal:2',
            'cancellation_fee' => 'decimal:2',
            'item_weight_kg' => 'decimal:2',
            'item_value' => 'decimal:2',
            // timestamps
            'status_changed_at' => 'datetime',
            'delivery_fee_paid_at' => 'datetime',
            'scheduled_for' => 'datetime',
            'returned_to_office_at' => 'datetime',
            'retrieved_by_seller_at' => 'datetime',
            'abandoned_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'awaiting_driver_at' => 'datetime',
            'no_driver_available_at' => 'datetime',
            'assigned_at' => 'datetime',
            'driver_en_route_pickup_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'driver_en_route_dropoff_at' => 'datetime',
            'delivery_in_progress_at' => 'datetime',
            'delivered_at' => 'datetime',
            'delivery_failed_at' => 'datetime',
            'returning_to_office_at' => 'datetime',
            'at_office_at' => 'datetime',
            'pickup_geofence_confirmed_at' => 'datetime',
            // small ints
            'pickup_code_attempts' => 'integer',
            'delivery_code_attempts' => 'integer',
            'driver_assignment_attempts' => 'integer',
            'search_radius_tier' => 'integer',
            'delivery_fee_surcharge_percent' => 'integer',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function receiverUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_user_id');
    }

    public function receiverGuest(): BelongsTo
    {
        return $this->belongsTo(GuestRecipient::class, 'receiver_guest_id');
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(MerchantProfile::class, 'merchant_profile_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function returnOffice(): BelongsTo
    {
        return $this->belongsTo(OfficeLocation::class, 'return_office_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(OrderStatusLog::class)->orderBy('created_at');
    }

    public function strikes(): HasMany
    {
        return $this->hasMany(DriverStrike::class, 'order_id');
    }

    /**
     * Settlements that included this order's cash.
     * Many-to-many for completeness (re-settlements, splits).
     */
    public function settlements(): BelongsToMany
    {
        return $this->belongsToMany(Settlement::class, 'settlement_orders', 'order_id', 'settlement_id')
            ->using(SettlementOrder::class)
            ->withPivot(['amount_contributed'])
            ->withTimestamps();
    }

    public function sellerEarning(): HasOne
    {
        return $this->hasOne(SellerEarning::class);
    }

    public function inventory(): HasOne
    {
        return $this->hasOne(OfficeInventory::class);
    }

    public function officeInventory(): HasOne
    {
        return $this->hasOne(OfficeInventory::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Predicates
    |--------------------------------------------------------------------------
    */

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function isCancellation(): bool
    {
        return $this->status->isCancellation();
    }

    public function isInReturnFlow(): bool
    {
        return $this->status->isReturnFlow();
    }

    public function hasDriver(): bool
    {
        return $this->driver_id !== null;
    }

    public function isMerchantOrder(): bool
    {
        return $this->order_type === OrderType::MerchantDelivery;
    }

    public function hasItemPrice(): bool
    {
        return $this->order_type->hasItemPrice();
    }

    /**
     * Total cash the driver collects from the receiver at delivery
     * (item price + delivery fee, when receiver pays cash for fee).
     */
    public function cashCollectedAtDelivery(): string
    {
        $cash = (string) $this->item_price;

        if ($this->delivery_fee_payer === DeliveryFeePayer::Receiver
            && $this->delivery_fee_payment_method === DeliveryFeePaymentMethod::Cash) {
            $cash = bcadd($cash, (string) $this->delivery_fee, 2);
        }

        return $cash;
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeWithStatus(Builder $query, OrderStatus ...$statuses): Builder
    {
        return $query->whereIn('status', array_map(static fn (OrderStatus $s): string => $s->value, $statuses));
    }

    public function scopeAwaitingDriver(Builder $query): Builder
    {
        return $query->where('status', OrderStatus::AwaitingDriver->value);
    }

    /**
     * Orders currently broadcasting and eligible for the radius-tier escalation job.
     */
    public function scopeBroadcasting(Builder $query): Builder
    {
        return $query->where('status', OrderStatus::AwaitingDriver->value);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', array_map(
            static fn (OrderStatus $s): string => $s->value,
            array_filter(OrderStatus::cases(), static fn (OrderStatus $s): bool => $s->isTerminal())
        ));
    }

    public function scopeActiveForDriver(Builder $query): Builder
    {
        return $query->whereIn('status', [
            OrderStatus::Assigned->value,
            OrderStatus::DriverEnRoutePickup->value,
            OrderStatus::PickedUp->value,
            OrderStatus::DriverEnRouteDropoff->value,
            OrderStatus::DeliveryInProgress->value,
            OrderStatus::DeliveryFailed->value,
            OrderStatus::ReturningToOffice->value,
        ]);
    }

    public function scopeForDriver(Builder $query, int $driverId): Builder
    {
        return $query->where('driver_id', $driverId);
    }

    public function scopeForSender(Builder $query, int $senderUserId): Builder
    {
        return $query->where('sender_user_id', $senderUserId);
    }

    public function scopeForReceiverUser(Builder $query, int $receiverUserId): Builder
    {
        return $query->where('receiver_user_id', $receiverUserId);
    }

    public function scopeForMerchant(Builder $query, int $merchantProfileId): Builder
    {
        return $query->where('merchant_profile_id', $merchantProfileId);
    }
}
