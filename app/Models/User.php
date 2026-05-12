<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\DriverDocumentType;
use Bavix\Wallet\Interfaces\Wallet;
use Bavix\Wallet\Interfaces\WalletFloat;
use Bavix\Wallet\Traits\HasWallet;
use Bavix\Wallet\Traits\HasWalletFloat;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Permission\Traits\HasRoles;

/**
 * The User model is intentionally non-final: Laravel's first-party auth/policy
 * layer expects to extend this class via traits and contracts; subclassing is
 * a documented Laravel extension point.
 */
class User extends Authenticatable implements HasMedia, Wallet, WalletFloat
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens;

    use HasFactory;
    use HasRoles;
    use HasWallet;
    use HasWalletFloat;
    use InteractsWithMedia;
    use Notifiable;

    /** @var array<int, string> */
    protected $fillable = [
        'first_name', 'last_name', 'email', 'password', 'phone_number', 'locale',
        'public_id', 'account_status', 'push_notifications_enabled',
        'sms_notifications_enabled', 'email_notifications_enabled',
        'fcm_token', 'fcm_token_updated_at',
        'phone_verified_at', 'email_verified_at',
    ];

    /** @var array<int, string> */
    protected $hidden = ['password', 'remember_token'];

    protected static function booted(): void
    {
        static::creating(static function (User $user): void {
            if (empty($user->public_id)) {
                $user->public_id = (string) Str::ulid();
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
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'suspended_at' => 'datetime',
            'last_active_at' => 'datetime',
            'fcm_token_updated_at' => 'datetime',
            'push_notifications_enabled' => 'boolean',
            'sms_notifications_enabled' => 'boolean',
            'email_notifications_enabled' => 'boolean',
            'password' => 'hashed',
            'account_status' => AccountStatus::class,
        ];
    }

    /**
     * Register one single-file Spatie media collection per DriverDocumentType.
     * Re-uploading replaces; the linkage between the `driver_documents` row
     * and the file is by convention (collection_name = 'driver_document_' . type).
     *
     * Mime-type allowlist is enforced at the FormRequest layer
     * (UploadDriverDocumentRequest mimes:jpeg,jpg,png,webp,pdf). We don't
     * duplicate it here because Spatie's check sniffs raw bytes and would
     * reject test uploads that don't have valid file headers.
     */
    public function registerMediaCollections(): void
    {
        foreach (DriverDocumentType::cases() as $type) {
            $this->addMediaCollection('driver_document_'.$type->value)
                ->singleFile();
        }
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.($this->last_name ?? ''));
    }

    public function isActive(): bool
    {
        return $this->account_status === AccountStatus::Active;
    }

    public function canLogin(): bool
    {
        return $this->account_status->canLogin();
    }

    public function canCreateOrders(): bool
    {
        return $this->account_status->canCreateOrders();
    }

    /*
    |--------------------------------------------------------------------------
    | Office staff relationships
    |--------------------------------------------------------------------------
    */

    public function officeStaffAssignments(): HasMany
    {
        return $this->hasMany(OfficeStaffAssignment::class);
    }

    public function offices(): BelongsToMany
    {
        return $this->belongsToMany(OfficeLocation::class, 'office_staff_assignments', 'user_id', 'office_id')
            ->using(OfficeStaffAssignment::class)
            ->withPivot(['is_manager', 'assigned_at', 'removed_at'])
            ->withTimestamps();
    }

    public function managedOffices(): HasMany
    {
        return $this->hasMany(OfficeLocation::class, 'manager_user_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Driver relationships (driver = user with driver_profile + 'driver' role)
    |--------------------------------------------------------------------------
    */

    public function driverProfile(): HasOne
    {
        return $this->hasOne(DriverProfile::class);
    }

    public function driverDocuments(): HasMany
    {
        return $this->hasMany(DriverDocument::class, 'driver_id');
    }

    public function driverRegions(): BelongsToMany
    {
        return $this->belongsToMany(Region::class, 'driver_region', 'driver_id', 'region_id')
            ->withTimestamps();
    }

    public function driverAccount(): HasOne
    {
        return $this->hasOne(DriverAccount::class, 'driver_id');
    }

    public function driverAccountTransactions(): HasMany
    {
        return $this->hasMany(DriverAccountTransaction::class, 'driver_id');
    }

    public function driverLocationHistory(): HasMany
    {
        return $this->hasMany(DriverLocation::class, 'driver_id');
    }

    public function driverStrikes(): HasMany
    {
        return $this->hasMany(DriverStrike::class, 'driver_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Merchant relationship
    |--------------------------------------------------------------------------
    */

    public function merchantProfile(): HasOne
    {
        return $this->hasOne(MerchantProfile::class);
    }

    public function isDriver(): bool
    {
        return $this->driverProfile()->exists();
    }

    public function isMerchant(): bool
    {
        return $this->merchantProfile()->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Order relationships
    |--------------------------------------------------------------------------
    */

    public function sentOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'sender_user_id');
    }

    public function receivedOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'receiver_user_id');
    }

    /**
     * Orders this user is currently driving (when they are also a driver).
     */
    public function deliveredOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'driver_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Settlement & payout relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Settlements where this user (driver) handed in cash.
     */
    public function driverSettlements(): HasMany
    {
        return $this->hasMany(Settlement::class, 'driver_id');
    }

    /**
     * Settlements processed by this user (office staff role).
     */
    public function processedSettlements(): HasMany
    {
        return $this->hasMany(Settlement::class, 'processed_by_staff_id');
    }

    public function sellerPayouts(): HasMany
    {
        return $this->hasMany(SellerPayout::class);
    }
}
