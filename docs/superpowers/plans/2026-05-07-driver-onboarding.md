# Driver Onboarding Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the complete driver onboarding lifecycle — from a user opting to become a driver, through office-staff walk-in completion (with documents), to admin approval (which auto-creates the 3-bucket driver_account and assigns the `driver` Spatie role), through driver self-service of region preferences and account view.

**Architecture:** Service-layer driven. Single unified onboarding flow that handles three user-resolution paths (existing pre-registered user, existing user without driver profile, cold walk-in with in-office OTP) through one controller + one service. Spatie roles + DriverProfilePolicy gate `/api/office/*`, `/api/admin/*`, and `/api/driver/*` namespaces. Documents stored in Spatie Media against User model; metadata in `driver_documents` linked by convention (`UNIQUE(driver_id, document_type)` + collection name `driver_document_{type}`). Approval is atomic — single DB::transaction creates account, assigns role, marks docs verified, transitions status.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL+PostGIS, Sanctum 4, Spatie Permission 7, Spatie Medialibrary 11, Pest 4 (deferred — using tinker smoke tests), Redis cache.

**Spec:** `docs/superpowers/specs/2026-05-07-driver-onboarding-design.md`

---

## File Structure

```
NEW
├── app/Enums/
│   └── DriverErrorCode.php
├── app/Policies/
│   └── DriverProfilePolicy.php
├── app/Services/Driver/
│   ├── DriverPreregistrationService.php
│   ├── DriverOnboardingService.php           (the multi-branch resolver)
│   ├── DriverDocumentService.php
│   ├── DriverApprovalService.php             (atomic approval)
│   ├── DriverStatusTransitionService.php     (reject / suspend / reinstate)
│   └── DriverRegionService.php
├── app/Http/Controllers/Api/Me/Driver/
│   ├── PreregistrationController.php          (POST /me/driver/preregister)
│   └── DriverProfileController.php            (GET  /me/driver)
├── app/Http/Controllers/Api/Office/
│   ├── DriverOnboardingController.php         (index/lookup/onboard/verifyPhone/submit)
│   └── DriverDocumentController.php           (store/destroy)
├── app/Http/Controllers/Api/Admin/
│   └── DriverController.php                   (index/show/approve/reject/suspend/reinstate)
├── app/Http/Controllers/Api/Driver/
│   ├── ProfileController.php                  (GET /driver/profile)
│   ├── RegionController.php                   (GET / PATCH /driver/regions)
│   └── AccountController.php                  (GET /driver/account)
├── app/Http/Requests/Driver/
│   ├── PreregisterDriverRequest.php
│   ├── LookupDriverRequest.php
│   ├── OnboardDriverRequest.php
│   ├── VerifyDriverPhoneRequest.php
│   ├── UploadDriverDocumentRequest.php
│   └── UpdateRegionsRequest.php
├── app/Http/Resources/
│   ├── DriverProfileResource.php              (summary)
│   ├── DriverProfileFullResource.php          (with documents + audit)
│   ├── DriverDocumentResource.php
│   ├── DriverAccountResource.php
│   └── RegionResource.php
├── database/seeders/TestStaffSeeder.php       (dev-only — seeds office_staff + admin)

MODIFIED
├── app/Models/User.php                        (add registerMediaCollections + auth-Spatie media collections per DriverDocumentType)
├── database/seeders/DatabaseSeeder.php        (call TestStaffSeeder when APP_ENV !== 'production')
└── routes/api.php                             (add /me/driver/*, /office/drivers/*, /admin/drivers/*, /driver/* groups)
```

**Zero new tables, zero new migrations** — schema readiness verified during brainstorm. Linkage between `driver_documents` rows and Spatie media files is by convention (collection name = `driver_document_{type}`), not by FK.

---

## Task 1: DriverErrorCode enum + DriverProfilePolicy + TestStaffSeeder

**Files:**
- Create: `app/Enums/DriverErrorCode.php`
- Create: `app/Policies/DriverProfilePolicy.php`
- Create: `database/seeders/TestStaffSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`

- [ ] **Step 1.1: Create `DriverErrorCode` enum.**

File: `app/Enums/DriverErrorCode.php`

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum DriverErrorCode: string
{
    case DriverProfileExists  = 'driver_profile_exists';
    case WrongOffice          = 'wrong_office';
    case InvalidState         = 'invalid_state';
    case MissingDocuments     = 'missing_documents';
    case PhoneNotVerified     = 'phone_not_verified';
    case LockedPostSubmission = 'locked_post_submission';
    case OutsideServiceArea   = 'outside_service_area';
    case OtpInvalid           = 'otp_invalid';
    case ValidationFailed     = 'validation_failed';

    public function httpStatus(): int
    {
        return match ($this) {
            self::DriverProfileExists, self::InvalidState => 409,
            self::WrongOffice, self::LockedPostSubmission => 403,
            self::MissingDocuments, self::PhoneNotVerified,
            self::OutsideServiceArea, self::OtpInvalid,
            self::ValidationFailed => 422,
        };
    }
}
```

- [ ] **Step 1.2: Create `DriverProfilePolicy`.**

File: `app/Policies/DriverProfilePolicy.php`

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DriverProfile;
use App\Models\User;

final class DriverProfilePolicy
{
    /**
     * Office staff can manage drivers tied to one of their assigned offices.
     * Admins always pass. Anyone else: deny.
     */
    public function manageInOffice(User $user, DriverProfile $driverProfile): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        if (! $user->hasRole('office_staff')) {
            return false;
        }

        // Staff member must be currently assigned (assignment not removed) to
        // an office that owns this driver_profile.
        return $user->officeStaffAssignments()
            ->whereNull('removed_at')
            ->where('office_id', $driverProfile->office_id)
            ->exists();
    }

    /** Driver themselves: only their own profile. */
    public function viewOwn(User $user, DriverProfile $driverProfile): bool
    {
        return $driverProfile->user_id === $user->id;
    }
}
```

- [ ] **Step 1.3: Create `TestStaffSeeder`.**

File: `database/seeders/TestStaffSeeder.php`

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AccountStatus;
use App\Models\OfficeLocation;
use App\Models\OfficeStaffAssignment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Dev-only seeder. Creates two known accounts so smoke tests have authenticated
 * staff and admin contexts:
 *   - +218910000001 / password123 → office_staff, assigned to first active office
 *   - +218910000002 / password123 → admin
 *
 * Skipped in production (called from DatabaseSeeder behind APP_ENV check).
 */
final class TestStaffSeeder extends Seeder
{
    public function run(): void
    {
        $office = OfficeLocation::query()->where('is_active', true)->first();
        if ($office === null) {
            $this->command->warn('TestStaffSeeder: no active office found; skipping.');

            return;
        }

        // Office staff
        $staff = User::firstOrCreate(
            ['phone_number' => '+218910000001'],
            [
                'first_name'        => 'Test',
                'last_name'         => 'Staff',
                'password'          => Hash::make('password123'),
                'phone_verified_at' => now(),
                'account_status'    => AccountStatus::Active,
                'locale'            => 'ar',
            ],
        );
        $staff->syncRoles(['office_staff']);

        OfficeStaffAssignment::firstOrCreate(
            ['user_id' => $staff->id, 'office_id' => $office->id],
            ['is_manager' => false, 'assigned_at' => now()],
        );

        // Admin
        $admin = User::firstOrCreate(
            ['phone_number' => '+218910000002'],
            [
                'first_name'        => 'Test',
                'last_name'         => 'Admin',
                'password'          => Hash::make('password123'),
                'phone_verified_at' => now(),
                'account_status'    => AccountStatus::Active,
                'locale'            => 'ar',
            ],
        );
        $admin->syncRoles(['admin']);

        $this->command->info('TestStaffSeeder: staff=+218910000001 admin=+218910000002 (password=password123)');
    }
}
```

- [ ] **Step 1.4: Wire seeder behind APP_ENV check.**

File: `database/seeders/DatabaseSeeder.php` — modify the `$this->call([...])` array to conditionally append `TestStaffSeeder`:

```php
public function run(): void
{
    $seeders = [
        RolesSeeder::class,
        PlatformSettingsSeeder::class,
        AuthSettingsSeeder::class,
    ];

    if (app()->environment(['local', 'development', 'testing'])) {
        $seeders[] = TestStaffSeeder::class;
    }

    $this->call($seeders);
}
```

- [ ] **Step 1.5: Run the seeder + verify (note: requires at least one active office).**

Run:
```bash
php artisan db:seed --class=TestStaffSeeder
php artisan tinker --execute="
\$s = App\Models\User::where('phone_number','+218910000001')->first();
\$a = App\Models\User::where('phone_number','+218910000002')->first();
echo 'staff: ' . (\$s ? 'id=' . \$s->id . ' roles=' . \$s->getRoleNames()->implode(',') : 'NOT FOUND') . PHP_EOL;
echo 'admin: ' . (\$a ? 'id=' . \$a->id . ' roles=' . \$a->getRoleNames()->implode(',') : 'NOT FOUND') . PHP_EOL;
echo 'staff offices: ' . (\$s ? \$s->officeStaffAssignments->pluck('office_id')->implode(',') : '-') . PHP_EOL;
"
```
Expected: staff has `office_staff` role + at least one office assignment; admin has `admin` role.

If no active office exists, create one via tinker first (the seeder will warn and skip). Setup helper:
```bash
php artisan tinker --execute="
use App\Models\ServiceArea;
use App\Models\Region;
use App\Models\OfficeLocation;
use Clickbar\Magellan\Data\Geometries\Point;
use Clickbar\Magellan\Data\Geometries\Polygon;
use Clickbar\Magellan\Data\Geometries\LineString;

if (OfficeLocation::query()->where('is_active', true)->doesntExist()) {
    \$sa = ServiceArea::create([
        'name' => 'Tripoli SA',
        'boundary' => Polygon::make([LineString::make([
            Point::make(13.0, 32.8), Point::make(13.4, 32.8),
            Point::make(13.4, 33.0), Point::make(13.0, 33.0),
            Point::make(13.0, 32.8),
        ])]),
        'is_active' => true,
    ]);
    \$r = Region::create([
        'service_area_id' => \$sa->id,
        'name' => 'Tripoli Center',
        'boundary' => Polygon::make([LineString::make([
            Point::make(13.1, 32.85), Point::make(13.3, 32.85),
            Point::make(13.3, 32.95), Point::make(13.1, 32.95),
            Point::make(13.1, 32.85),
        ])]),
        'is_active' => true,
    ]);
    \$o = OfficeLocation::create([
        'region_id' => \$r->id,
        'name' => 'Main Office',
        'address' => '123 Main St, Tripoli',
        'location' => Point::make(13.2, 32.9),
        'is_active' => true,
    ]);
    echo 'Seeded test office id=' . \$o->id . PHP_EOL;
}
"
php artisan db:seed --class=TestStaffSeeder
```

---

## Task 2: JsonResources

**Files:**
- Create: `app/Http/Resources/DriverProfileResource.php`
- Create: `app/Http/Resources/DriverProfileFullResource.php`
- Create: `app/Http/Resources/DriverDocumentResource.php`
- Create: `app/Http/Resources/DriverAccountResource.php`
- Create: `app/Http/Resources/RegionResource.php`

(No dedicated smoke test — exercised via downstream tasks.)

- [ ] **Step 2.1: `DriverProfileResource` (summary).**

File: `app/Http/Resources/DriverProfileResource.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\DriverProfile */
final class DriverProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'user_id'         => $this->user_id,
            'office_id'       => $this->office_id,
            'status'          => $this->status->value,
            'activity_status' => $this->activity_status->value,
            'vehicle_type'    => $this->vehicle_type->value,
            'vehicle_plate'   => $this->vehicle_plate,
            'vehicle_color'   => $this->vehicle_color,
            'vehicle_model'   => $this->vehicle_model,
            'lifetime_deliveries' => $this->lifetime_deliveries,
            'rating_average'  => $this->rating_average,
            'created_at'      => $this->created_at?->toIso8601String(),
            'approved_at'     => $this->approved_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 2.2: `DriverDocumentResource`.**

File: `app/Http/Resources/DriverDocumentResource.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\DriverDocument */
final class DriverDocumentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // Convention link: file lives in Spatie media collection
        // 'driver_document_'.{document_type} on the User model.
        $user = $this->driver;
        $collection = 'driver_document_' . $this->document_type->value;
        $media = $user?->getFirstMedia($collection);

        return [
            'id'             => $this->id,
            'document_type'  => $this->document_type->value,
            'verified'       => (bool) $this->verified,
            'verified_at'    => $this->verified_at?->toIso8601String(),
            'expires_at'     => $this->expires_at?->toDateString(),
            'notes'          => $this->notes,
            'file_url'       => $media?->getTemporaryUrl(now()->addMinutes(15)) ?? $media?->getUrl(),
            'file_name'      => $media?->file_name,
            'file_size'      => $media?->size,
            'mime_type'      => $media?->mime_type,
            'created_at'     => $this->created_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 2.3: `DriverProfileFullResource` (admin detail).**

File: `app/Http/Resources/DriverProfileFullResource.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DriverDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\DriverProfile */
final class DriverProfileFullResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $documents = DriverDocument::where('driver_id', $this->user_id)->get();

        return [
            'id'              => $this->id,
            'status'          => $this->status->value,
            'activity_status' => $this->activity_status->value,
            'office_id'       => $this->office_id,
            'office'          => $this->relationLoaded('office')
                ? ['id' => $this->office->id, 'name' => $this->office->name]
                : null,
            'user' => $this->relationLoaded('user') ? [
                'id'                => $this->user->public_id,
                'first_name'        => $this->user->first_name,
                'last_name'         => $this->user->last_name,
                'phone_number'      => $this->user->phone_number,
                'phone_verified'    => $this->user->phone_verified_at !== null,
                'email'             => $this->user->email,
                'email_verified'    => $this->user->email_verified_at !== null,
                'account_status'    => $this->user->account_status->value,
            ] : null,
            'vehicle' => [
                'type'  => $this->vehicle_type->value,
                'plate' => $this->vehicle_plate,
                'color' => $this->vehicle_color,
                'model' => $this->vehicle_model,
            ],
            'documents' => DriverDocumentResource::collection($documents),
            'audit' => [
                'created_at'  => $this->created_at?->toIso8601String(),
                'approved_at' => $this->approved_at?->toIso8601String(),
                'approved_by_admin_id' => $this->approved_by_admin_id,
                'rejected_at' => $this->rejected_at?->toIso8601String(),
            ],
            'lifetime_deliveries' => $this->lifetime_deliveries,
            'rating_average'      => $this->rating_average,
            'notes'               => $this->notes,
        ];
    }
}
```

- [ ] **Step 2.4: `DriverAccountResource`.**

File: `app/Http/Resources/DriverAccountResource.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\DriverAccount */
final class DriverAccountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'cash_to_deposit'             => $this->cash_to_deposit,
            'earnings_balance'            => $this->earnings_balance,
            'debt_balance'                => $this->debt_balance,
            'max_cash_liability'          => $this->max_cash_liability,
            'lifetime_earnings'           => $this->lifetime_earnings,
            'lifetime_cash_handled'       => $this->lifetime_cash_handled,
            'lifetime_platform_fees_paid' => $this->lifetime_platform_fees_paid,
            'net_position'                => $this->net_position,
        ];
    }
}
```

- [ ] **Step 2.5: `RegionResource`.**

File: `app/Http/Resources/RegionResource.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Region */
final class RegionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'service_area_id' => $this->service_area_id,
            'is_active'       => (bool) $this->is_active,
        ];
    }
}
```

---

## Task 3: User model — register Spatie media collections per document type

**Files:**
- Modify: `app/Models/User.php`

- [ ] **Step 3.1: Add `registerMediaCollections()` method to User.**

The User model already implements `HasMedia` and uses `InteractsWithMedia`. We add one **single-file** collection per `DriverDocumentType` so re-uploading replaces the file automatically.

In `app/Models/User.php`, add after `casts()`:

```php
public function registerMediaCollections(): void
{
    foreach (\App\Enums\DriverDocumentType::cases() as $type) {
        $this->addMediaCollection('driver_document_' . $type->value)
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
    }
}
```

- [ ] **Step 3.2: Verify via tinker — collection registration is invoked.**

```bash
php artisan tinker --execute="
\$u = App\Models\User::first();
\$collections = \$u->getRegisteredMediaCollections();
foreach (\$collections as \$c) {
    if (str_starts_with(\$c->name, 'driver_document_')) {
        echo \$c->name . ' (singleFile=' . (\$c->singleFile ? 'true' : 'false') . ')' . PHP_EOL;
    }
}
"
```
Expected output: 8 lines, one per `DriverDocumentType` case (national_id_front, national_id_back, drivers_license, vehicle_registration, selfie, vehicle_photo_front, vehicle_photo_back, insurance), each `singleFile=true`.

---

## Task 4: Pre-registration endpoints (`/api/me/driver/*`)

**Files:**
- Create: `app/Services/Driver/DriverPreregistrationService.php`
- Create: `app/Http/Requests/Driver/PreregisterDriverRequest.php`
- Create: `app/Http/Controllers/Api/Me/Driver/PreregistrationController.php`
- Create: `app/Http/Controllers/Api/Me/Driver/DriverProfileController.php`
- Modify: `routes/api.php`

- [ ] **Step 4.1: Create `PreregisterDriverRequest`.**

File: `app/Http/Requests/Driver/PreregisterDriverRequest.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Driver;

use App\Enums\VehicleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PreregisterDriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->phone_verified_at !== null
            && $user->isActive();
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'office_id'     => ['required', 'integer', Rule::exists('office_locations', 'id')->where('is_active', true)],
            'vehicle_type'  => ['required', Rule::enum(VehicleType::class)],
            'vehicle_plate' => ['required', 'string', 'min:1', 'max:32'],
            'vehicle_color' => ['nullable', 'string', 'min:1', 'max:32'],
            'vehicle_model' => ['nullable', 'string', 'min:1', 'max:64'],
        ];
    }
}
```

- [ ] **Step 4.2: Create `DriverPreregistrationService`.**

File: `app/Services/Driver/DriverPreregistrationService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Driver;

use App\Enums\DriverActivityStatus;
use App\Enums\DriverErrorCode;
use App\Enums\DriverStatus;
use App\Enums\VehicleType;
use App\Models\DriverProfile;
use App\Models\User;

final class DriverPreregistrationService
{
    /**
     * @param  array{
     *     office_id: int,
     *     vehicle_type: string,
     *     vehicle_plate: string,
     *     vehicle_color?: ?string,
     *     vehicle_model?: ?string,
     * }  $data
     * @return DriverProfile|DriverErrorCode
     */
    public function preregister(User $user, array $data): DriverProfile|DriverErrorCode
    {
        if ($user->driverProfile()->exists()) {
            return DriverErrorCode::DriverProfileExists;
        }

        return DriverProfile::create([
            'user_id'         => $user->id,
            'office_id'       => $data['office_id'],
            'status'          => DriverStatus::PreRegistered,
            'activity_status' => DriverActivityStatus::Offline,
            'vehicle_type'    => VehicleType::from($data['vehicle_type']),
            'vehicle_plate'   => $data['vehicle_plate'],
            'vehicle_color'   => $data['vehicle_color'] ?? null,
            'vehicle_model'   => $data['vehicle_model'] ?? null,
        ]);
    }
}
```

- [ ] **Step 4.3: Create `PreregistrationController`.**

File: `app/Http/Controllers/Api/Me/Driver/PreregistrationController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Me\Driver;

use App\Enums\DriverErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\PreregisterDriverRequest;
use App\Http\Resources\DriverProfileResource;
use App\Models\User;
use App\Services\Driver\DriverPreregistrationService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class PreregistrationController extends Controller
{
    public function __construct(private readonly DriverPreregistrationService $service) {}

    public function __invoke(PreregisterDriverRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $result = $this->service->preregister($user, $request->validated());

        if ($result instanceof DriverErrorCode) {
            return response()->json([
                'error'   => $result->value,
                'message' => 'You already have a driver profile.',
            ], $result->httpStatus());
        }

        return response()->json([
            'driver_profile' => (new DriverProfileResource($result))->resolve($request),
        ], Response::HTTP_CREATED);
    }
}
```

- [ ] **Step 4.4: Create `DriverProfileController` (the GET).**

File: `app/Http/Controllers/Api/Me/Driver/DriverProfileController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Me\Driver;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriverProfileResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DriverProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $user->driverProfile;

        return response()->json([
            'driver_profile' => $profile === null
                ? null
                : (new DriverProfileResource($profile))->resolve($request),
        ]);
    }
}
```

- [ ] **Step 4.5: Wire routes.**

File: `routes/api.php` — add inside the existing imports + at the top of the auth-required `me` namespace area:

Add to imports:
```php
use App\Http\Controllers\Api\Me\Driver\DriverProfileController;
use App\Http\Controllers\Api\Me\Driver\PreregistrationController;
```

Add after the existing `me/profile` route block:
```php
Route::middleware('auth:sanctum')->prefix('me/driver')->group(function (): void {
    Route::get('/', [DriverProfileController::class, 'show']);
    Route::post('preregister', PreregistrationController::class);
});
```

- [ ] **Step 4.6: Smoke test.**

Write to `storage/app/smoke_driver_preregister.php`:

```php
<?php

declare(strict_types=1);

use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;

Cache::flush();
User::where('phone_number', '+218910000300')->forceDelete();

$user = User::create([
    'first_name' => 'PreReg',
    'phone_number' => '+218910000300',
    'password' => 'pass1234',
    'phone_verified_at' => now(),
    'account_status' => 'active',
]);
$user->assignRole('user');

$officeId = \App\Models\OfficeLocation::query()->where('is_active', true)->value('id');
$token = $user->createToken('test')->plainTextToken;

$kernel = app(\Illuminate\Contracts\Http\Kernel::class);
$call = function (string $method, string $uri, array $payload = [], ?string $bearer = null) use ($kernel): TestResponse {
    Auth::forgetGuards();
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    if ($bearer !== null) {
        $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $bearer;
    }
    $req = \Illuminate\Http\Request::create($uri, $method, server: $server, content: (string) json_encode($payload));
    $req->headers->set('Content-Type', 'application/json');
    return new TestResponse($kernel->handle($req));
};

echo "1. GET /api/me/driver — no profile yet" . PHP_EOL;
$resp = $call('GET', '/api/me/driver', bearer: $token);
echo "  status=" . $resp->status() . " (expected 200)" . PHP_EOL;
echo "  driver_profile=" . var_export($resp->json('driver_profile'), true) . " (expected null)" . PHP_EOL;

echo PHP_EOL . "2. POST /api/me/driver/preregister" . PHP_EOL;
$resp = $call('POST', '/api/me/driver/preregister', [
    'office_id' => $officeId,
    'vehicle_type' => 'car',
    'vehicle_plate' => 'TPL-123',
    'vehicle_color' => 'red',
    'vehicle_model' => 'Toyota Corolla',
], bearer: $token);
echo "  status=" . $resp->status() . " (expected 201)" . PHP_EOL;
echo "  status field=" . $resp->json('driver_profile.status') . " (expected pre_registered)" . PHP_EOL;

echo PHP_EOL . "3. POST again — must reject as driver_profile_exists" . PHP_EOL;
$resp = $call('POST', '/api/me/driver/preregister', [
    'office_id' => $officeId,
    'vehicle_type' => 'motorcycle',
    'vehicle_plate' => 'TPL-456',
], bearer: $token);
echo "  status=" . $resp->status() . " (expected 409)" . PHP_EOL;
echo "  error=" . $resp->json('error') . " (expected driver_profile_exists)" . PHP_EOL;

echo PHP_EOL . "4. GET /api/me/driver — now returns the profile" . PHP_EOL;
$resp = $call('GET', '/api/me/driver', bearer: $token);
echo "  status=" . $resp->status() . PHP_EOL;
echo "  vehicle_plate=" . $resp->json('driver_profile.vehicle_plate') . PHP_EOL;

echo PHP_EOL . "5. Missing bearer → 401" . PHP_EOL;
$resp = $call('POST', '/api/me/driver/preregister', ['office_id' => $officeId, 'vehicle_type' => 'car', 'vehicle_plate' => 'X']);
echo "  status=" . $resp->status() . " (expected 401)" . PHP_EOL;

DriverProfile::where('user_id', $user->id)->forceDelete();
$user->forceDelete();
echo PHP_EOL . "Done." . PHP_EOL;
```

Run: `php artisan tinker storage/app/smoke_driver_preregister.php`

Expected (concise): 200 (null), 201 (pre_registered), 409 (driver_profile_exists), 200 (TPL-123), 401.

After verifying: `rm storage/app/smoke_driver_preregister.php`

---

## Task 5: Office staff lookup endpoint

**Files:**
- Create: `app/Http/Requests/Driver/LookupDriverRequest.php`
- Create: `app/Services/Driver/DriverOnboardingService.php` (start of it — `findByPhoneForOffice`)
- Create: `app/Http/Controllers/Api/Office/DriverOnboardingController.php` (start — `lookup` action)
- Modify: `routes/api.php`

- [ ] **Step 5.1: Create `LookupDriverRequest`.**

File: `app/Http/Requests/Driver/LookupDriverRequest.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Driver;

use Illuminate\Foundation\Http\FormRequest;

final class LookupDriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('office_staff') ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'phone_number' => ['required', 'string', 'regex:/^\+218\d{9}$/'],
        ];
    }
}
```

- [ ] **Step 5.2: Create `DriverOnboardingService` (with `findByPhoneForOffice` method only — `onboard` lands in Task 6).**

File: `app/Services/Driver/DriverOnboardingService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Driver;

use App\Enums\DriverStatus;
use App\Models\DriverProfile;
use App\Models\User;

final class DriverOnboardingService
{
    /**
     * @return array{
     *     user_exists: bool,
     *     user_phone_verified: bool,
     *     driver_profile: ?DriverProfile,
     *     can_onboard: bool,
     *     reason_if_not: ?string,
     * }
     */
    public function findByPhoneForOffice(string $phone, int $staffOfficeId): array
    {
        $user = User::where('phone_number', $phone)->first();

        if ($user === null) {
            return [
                'user_exists'         => false,
                'user_phone_verified' => false,
                'driver_profile'      => null,
                'can_onboard'         => true,
                'reason_if_not'       => null,
            ];
        }

        $profile = $user->driverProfile;

        if ($profile === null) {
            return [
                'user_exists'         => true,
                'user_phone_verified' => $user->phone_verified_at !== null,
                'driver_profile'      => null,
                'can_onboard'         => true,
                'reason_if_not'       => null,
            ];
        }

        // Profile exists — only on this office in pre_registered or
        // pending_approval can the staff continue onboarding.
        if ($profile->office_id !== $staffOfficeId) {
            return [
                'user_exists'         => true,
                'user_phone_verified' => $user->phone_verified_at !== null,
                'driver_profile'      => $profile,
                'can_onboard'         => false,
                'reason_if_not'       => 'belongs_to_other_office',
            ];
        }

        $canContinue = in_array($profile->status, [DriverStatus::PreRegistered, DriverStatus::PendingApproval], true);

        return [
            'user_exists'         => true,
            'user_phone_verified' => $user->phone_verified_at !== null,
            'driver_profile'      => $profile,
            'can_onboard'         => $canContinue,
            'reason_if_not'       => $canContinue ? null : 'profile_state_' . $profile->status->value,
        ];
    }
}
```

- [ ] **Step 5.3: Create `DriverOnboardingController` with `lookup` action.**

File: `app/Http/Controllers/Api/Office/DriverOnboardingController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Office;

use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\LookupDriverRequest;
use App\Http\Resources\DriverProfileResource;
use App\Models\User;
use App\Services\Driver\DriverOnboardingService;
use Illuminate\Http\JsonResponse;

final class DriverOnboardingController extends Controller
{
    public function __construct(private readonly DriverOnboardingService $service) {}

    public function lookup(LookupDriverRequest $request): JsonResponse
    {
        /** @var User $staff */
        $staff = $request->user();
        $officeId = $this->staffOfficeId($staff);

        $result = $this->service->findByPhoneForOffice(
            (string) $request->input('phone_number'),
            $officeId,
        );

        return response()->json([
            'user_exists'         => $result['user_exists'],
            'user_phone_verified' => $result['user_phone_verified'],
            'driver_profile'      => $result['driver_profile']
                ? (new DriverProfileResource($result['driver_profile']))->resolve($request)
                : null,
            'can_onboard'   => $result['can_onboard'],
            'reason_if_not' => $result['reason_if_not'],
        ]);
    }

    /**
     * Returns the office_id that the authenticated staff member is assigned
     * to. Throws if they have no active assignment (their `auth:sanctum +
     * role:office_staff` middleware passes, but they have no office in
     * `office_staff_assignments` — corner case for staff whose assignment was
     * removed).
     */
    protected function staffOfficeId(User $staff): int
    {
        $assignment = $staff->officeStaffAssignments()
            ->whereNull('removed_at')
            ->first();

        abort_if($assignment === null, 403, 'Staff member has no active office assignment.');

        return $assignment->office_id;
    }
}
```

- [ ] **Step 5.4: Wire routes.**

File: `routes/api.php` — add to imports:

```php
use App\Http\Controllers\Api\Office\DriverOnboardingController;
```

Add new route group at bottom (before `Route::get('/user', ...)` default):

```php
Route::middleware(['auth:sanctum', 'role:office_staff'])->prefix('office/drivers')->group(function (): void {
    Route::post('lookup', [DriverOnboardingController::class, 'lookup']);
});
```

- [ ] **Step 5.5: Smoke test.**

Write to `storage/app/smoke_office_lookup.php`:

```php
<?php

declare(strict_types=1);

use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;

Cache::flush();
User::where('phone_number', '+218910000301')->forceDelete();
User::where('phone_number', '+218910000302')->forceDelete();

$staff = User::where('phone_number', '+218910000001')->firstOrFail();
$staffToken = $staff->createToken('test-staff')->plainTextToken;
$officeId = $staff->officeStaffAssignments()->whereNull('removed_at')->first()->office_id;

$kernel = app(\Illuminate\Contracts\Http\Kernel::class);
$call = function (string $uri, array $payload, string $bearer) use ($kernel): TestResponse {
    Auth::forgetGuards();
    $req = \Illuminate\Http\Request::create($uri, 'POST', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer ' . $bearer,
    ], content: (string) json_encode($payload));
    $req->headers->set('Content-Type', 'application/json');
    return new TestResponse($kernel->handle($req));
};

echo "1. Lookup unknown phone" . PHP_EOL;
$resp = $call('/api/office/drivers/lookup', ['phone_number' => '+218910000301'], $staffToken);
echo "  user_exists=" . var_export($resp->json('user_exists'), true) . " (expected false)" . PHP_EOL;
echo "  can_onboard=" . var_export($resp->json('can_onboard'), true) . " (expected true)" . PHP_EOL;

echo PHP_EOL . "2. Existing user, no driver_profile" . PHP_EOL;
$user = User::create([
    'first_name' => 'Existing',
    'phone_number' => '+218910000301',
    'password' => 'pass1234',
    'phone_verified_at' => now(),
    'account_status' => 'active',
]);
$resp = $call('/api/office/drivers/lookup', ['phone_number' => '+218910000301'], $staffToken);
echo "  user_exists=" . var_export($resp->json('user_exists'), true) . " (expected true)" . PHP_EOL;
echo "  user_phone_verified=" . var_export($resp->json('user_phone_verified'), true) . " (expected true)" . PHP_EOL;
echo "  driver_profile=" . var_export($resp->json('driver_profile'), true) . " (expected null)" . PHP_EOL;
echo "  can_onboard=" . var_export($resp->json('can_onboard'), true) . " (expected true)" . PHP_EOL;

echo PHP_EOL . "3. Existing user with pre_registered profile in same office" . PHP_EOL;
DriverProfile::create([
    'user_id' => $user->id,
    'office_id' => $officeId,
    'status' => 'pre_registered',
    'activity_status' => 'offline',
    'vehicle_type' => 'car',
    'vehicle_plate' => 'TPL-LOOKUP-1',
]);
$resp = $call('/api/office/drivers/lookup', ['phone_number' => '+218910000301'], $staffToken);
echo "  driver_profile.status=" . $resp->json('driver_profile.status') . " (expected pre_registered)" . PHP_EOL;
echo "  can_onboard=" . var_export($resp->json('can_onboard'), true) . " (expected true)" . PHP_EOL;

echo PHP_EOL . "4. Different-office driver_profile" . PHP_EOL;
$user2 = User::create([
    'first_name' => 'Other',
    'phone_number' => '+218910000302',
    'password' => 'pass',
    'phone_verified_at' => now(),
    'account_status' => 'active',
]);
DriverProfile::create([
    'user_id' => $user2->id,
    'office_id' => $officeId + 9999, // intentionally non-existent or different
    'status' => 'pending_approval',
    'activity_status' => 'offline',
    'vehicle_type' => 'motorcycle',
    'vehicle_plate' => 'TPL-LOOKUP-2',
]);
$resp = $call('/api/office/drivers/lookup', ['phone_number' => '+218910000302'], $staffToken);
echo "  can_onboard=" . var_export($resp->json('can_onboard'), true) . " (expected false)" . PHP_EOL;
echo "  reason_if_not=" . $resp->json('reason_if_not') . " (expected belongs_to_other_office)" . PHP_EOL;

echo PHP_EOL . "5. Non-staff bearer → 403" . PHP_EOL;
$resp = $call('/api/office/drivers/lookup', ['phone_number' => '+218910000301'], $user->createToken('not-staff')->plainTextToken);
echo "  status=" . $resp->status() . " (expected 403)" . PHP_EOL;

DriverProfile::where('user_id', $user->id)->forceDelete();
DriverProfile::where('user_id', $user2->id)->forceDelete();
$user->forceDelete();
$user2->forceDelete();
echo PHP_EOL . "Done." . PHP_EOL;
```

Run: `php artisan tinker storage/app/smoke_office_lookup.php`. Expected: scenarios 1-4 match expectations, 5 returns 403.

After verifying: `rm storage/app/smoke_office_lookup.php`

---

## Task 6: Office staff onboard endpoint (multi-branch resolver)

**Files:**
- Create: `app/Http/Requests/Driver/OnboardDriverRequest.php`
- Modify: `app/Services/Driver/DriverOnboardingService.php` (add `onboard` method)
- Modify: `app/Http/Controllers/Api/Office/DriverOnboardingController.php` (add `onboard` action)
- Modify: `routes/api.php`

- [ ] **Step 6.1: Create `OnboardDriverRequest`.**

File: `app/Http/Requests/Driver/OnboardDriverRequest.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Driver;

use App\Enums\VehicleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class OnboardDriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('office_staff') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'phone_number'  => ['required', 'string', 'regex:/^\+218\d{9}$/'],
            'first_name'    => ['required_without:user_id', 'string', 'min:1', 'max:100'],
            'last_name'     => ['nullable', 'string', 'min:1', 'max:100'],
            'vehicle_type'  => ['required', Rule::enum(VehicleType::class)],
            'vehicle_plate' => ['required', 'string', 'min:1', 'max:32'],
            'vehicle_color' => ['nullable', 'string', 'min:1', 'max:32'],
            'vehicle_model' => ['nullable', 'string', 'min:1', 'max:64'],
        ];
    }
}
```

- [ ] **Step 6.2: Add `onboard` method to `DriverOnboardingService`.**

Edit `app/Services/Driver/DriverOnboardingService.php` — add these imports + this method to the existing class:

Imports:
```php
use App\Enums\AccountStatus;
use App\Enums\DriverActivityStatus;
use App\Enums\DriverErrorCode;
use App\Enums\OtpPurpose;
use App\Enums\VehicleType;
use App\Services\Auth\OtpService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
```

Add a constructor (replace empty constructor if present):
```php
public function __construct(private readonly OtpService $otp)
{
}
```

Add `onboard` method:
```php
/**
 * Single-entry onboarding for office staff. Resolves the user (existing
 * pre-registered, existing without profile, or no user at all), ensures a
 * driver_profile exists in pre_registered for THIS staff member's office,
 * and triggers an in-office OTP if the user was just created.
 *
 * @param  array{
 *     phone_number: string,
 *     first_name?: ?string,
 *     last_name?: ?string,
 *     vehicle_type: string,
 *     vehicle_plate: string,
 *     vehicle_color?: ?string,
 *     vehicle_model?: ?string,
 * }  $data
 *
 * @return array{
 *     driver_profile: \App\Models\DriverProfile,
 *     otp_required: bool,
 *     otp_expires_at: ?\Carbon\CarbonInterface,
 * } | DriverErrorCode
 */
public function onboard(int $staffOfficeId, array $data): array|DriverErrorCode
{
    return DB::transaction(function () use ($staffOfficeId, $data): array|DriverErrorCode {
        $phone = $data['phone_number'];
        $user = \App\Models\User::where('phone_number', $phone)->first();
        $otpRequired = false;

        if ($user === null) {
            // Path C — cold walk-in. Create user with phone_verified_at=null.
            // We set a random throwaway password (driver doesn't login until
            // they go through the full OTP loop and reset their password later).
            $user = \App\Models\User::create([
                'phone_number'      => $phone,
                'first_name'        => $data['first_name'] ?? '',
                'last_name'         => $data['last_name'] ?? null,
                'password'          => Str::random(32),  // 'hashed' cast handles hashing
                'locale'            => 'ar',
                'account_status'    => AccountStatus::Active,
                'phone_verified_at' => null,
            ]);
            $user->assignRole('user');
            $otpRequired = true;
        }

        $profile = $user->driverProfile;

        if ($profile !== null) {
            // Existing profile — must be in this office (otherwise lookup
            // would have refused; defense in depth).
            if ($profile->office_id !== $staffOfficeId) {
                return DriverErrorCode::WrongOffice;
            }
            // Update vehicle data (staff might be correcting plate, etc.).
            $profile->update([
                'vehicle_type'  => VehicleType::from($data['vehicle_type']),
                'vehicle_plate' => $data['vehicle_plate'],
                'vehicle_color' => $data['vehicle_color'] ?? $profile->vehicle_color,
                'vehicle_model' => $data['vehicle_model'] ?? $profile->vehicle_model,
            ]);
        } else {
            // Path B (or end of Path C) — create fresh.
            $profile = \App\Models\DriverProfile::create([
                'user_id'         => $user->id,
                'office_id'       => $staffOfficeId,
                'status'          => \App\Enums\DriverStatus::PreRegistered,
                'activity_status' => DriverActivityStatus::Offline,
                'vehicle_type'    => VehicleType::from($data['vehicle_type']),
                'vehicle_plate'   => $data['vehicle_plate'],
                'vehicle_color'   => $data['vehicle_color'] ?? null,
                'vehicle_model'   => $data['vehicle_model'] ?? null,
            ]);
        }

        // Outside the if/else so all newly-created cold-walk-in users get an
        // OTP. The user-update branch (path B with verified phone) skips this.
        $otpExpiresAt = null;
        if ($otpRequired) {
            $this->otp->issue($phone, OtpPurpose::Registration);
            $otpExpiresAt = now()->addSeconds((int) \App\Models\PlatformSetting::get('otp_ttl_seconds', 300));
        }

        return [
            'driver_profile' => $profile,
            'otp_required'   => $otpRequired,
            'otp_expires_at' => $otpExpiresAt,
        ];
    });
}
```

- [ ] **Step 6.3: Add `onboard` action to `DriverOnboardingController`.**

Add to the existing controller class:

```php
public function onboard(\App\Http\Requests\Driver\OnboardDriverRequest $request): JsonResponse
{
    /** @var User $staff */
    $staff = $request->user();
    $officeId = $this->staffOfficeId($staff);

    $result = $this->service->onboard($officeId, $request->validated());

    if ($result instanceof \App\Enums\DriverErrorCode) {
        return response()->json([
            'error'   => $result->value,
            'message' => 'Cannot onboard this driver under your office.',
        ], $result->httpStatus());
    }

    return response()->json([
        'driver_profile' => (new DriverProfileResource($result['driver_profile']))->resolve($request),
        'otp_required'   => $result['otp_required'],
        'otp_expires_at' => $result['otp_expires_at']?->toIso8601String(),
    ], 201);
}
```

- [ ] **Step 6.4: Wire route.**

File: `routes/api.php` — inside the existing `office/drivers` group, add:
```php
    Route::post('onboard', [DriverOnboardingController::class, 'onboard']);
```

- [ ] **Step 6.5: Smoke test all three paths.**

Write to `storage/app/smoke_office_onboard.php`:

```php
<?php

declare(strict_types=1);

use App\Models\DriverProfile;
use App\Models\User;
use App\Services\Sms\Drivers\FakeSmsDriver;
use App\Services\Sms\SmsService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;

app()->forgetInstance(SmsService::class);
app()->singleton(SmsService::class, FakeSmsDriver::class);
$sms = app(SmsService::class);

Cache::flush();
foreach (['+218910000311', '+218910000312', '+218910000313'] as $p) {
    User::where('phone_number', $p)->forceDelete();
}

$staff = User::where('phone_number', '+218910000001')->firstOrFail();
$staffToken = $staff->createToken('test')->plainTextToken;

$kernel = app(\Illuminate\Contracts\Http\Kernel::class);
$call = function (string $uri, array $payload, string $bearer) use ($kernel): TestResponse {
    Auth::forgetGuards();
    $req = \Illuminate\Http\Request::create($uri, 'POST', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer ' . $bearer,
    ], content: (string) json_encode($payload));
    $req->headers->set('Content-Type', 'application/json');
    return new TestResponse($kernel->handle($req));
};

echo "PATH A — existing user with pre_registered profile (already in this office)" . PHP_EOL;
$officeId = $staff->officeStaffAssignments()->whereNull('removed_at')->first()->office_id;
$userA = User::create([
    'first_name' => 'PreRegA',
    'phone_number' => '+218910000311',
    'password' => 'pass',
    'phone_verified_at' => now(),
    'account_status' => 'active',
]);
$userA->assignRole('user');
DriverProfile::create([
    'user_id' => $userA->id,
    'office_id' => $officeId,
    'status' => 'pre_registered',
    'activity_status' => 'offline',
    'vehicle_type' => 'car',
    'vehicle_plate' => 'OLD-PLATE',
]);
$resp = $call('/api/office/drivers/onboard', [
    'phone_number' => '+218910000311',
    'vehicle_type' => 'car',
    'vehicle_plate' => 'NEW-PLATE',
    'vehicle_color' => 'blue',
], $staffToken);
echo "  status=" . $resp->status() . " (expected 201)" . PHP_EOL;
echo "  vehicle_plate=" . $resp->json('driver_profile.vehicle_plate') . " (expected NEW-PLATE — staff updated)" . PHP_EOL;
echo "  otp_required=" . var_export($resp->json('otp_required'), true) . " (expected false)" . PHP_EOL;

echo PHP_EOL . "PATH B — existing user, no driver_profile" . PHP_EOL;
$userB = User::create([
    'first_name' => 'NoProfileB',
    'phone_number' => '+218910000312',
    'password' => 'pass',
    'phone_verified_at' => now(),
    'account_status' => 'active',
]);
$userB->assignRole('user');
$resp = $call('/api/office/drivers/onboard', [
    'phone_number' => '+218910000312',
    'vehicle_type' => 'motorcycle',
    'vehicle_plate' => 'M-001',
], $staffToken);
echo "  status=" . $resp->status() . " (expected 201)" . PHP_EOL;
echo "  status field=" . $resp->json('driver_profile.status') . " (expected pre_registered)" . PHP_EOL;
echo "  otp_required=" . var_export($resp->json('otp_required'), true) . " (expected false — phone already verified)" . PHP_EOL;

echo PHP_EOL . "PATH C — cold walk-in (user does not exist)" . PHP_EOL;
$resp = $call('/api/office/drivers/onboard', [
    'phone_number' => '+218910000313',
    'first_name'   => 'ColdWalkIn',
    'last_name'    => 'Driver',
    'vehicle_type' => 'car',
    'vehicle_plate' => 'COLD-1',
], $staffToken);
echo "  status=" . $resp->status() . " (expected 201)" . PHP_EOL;
$createdUser = User::where('phone_number', '+218910000313')->firstOrFail();
echo "  user created: id=" . $createdUser->id . PHP_EOL;
echo "  user.phone_verified_at=" . ($createdUser->phone_verified_at?->toIso8601String() ?? 'null (good — needs in-office OTP)') . PHP_EOL;
echo "  otp_required=" . var_export($resp->json('otp_required'), true) . " (expected true)" . PHP_EOL;
echo "  OTP captured by FakeSmsDriver: " . $sms->lastCodeFor('+218910000313') . PHP_EOL;

echo PHP_EOL . "Cleanup" . PHP_EOL;
foreach (['+218910000311', '+218910000312', '+218910000313'] as $p) {
    $u = User::where('phone_number', $p)->first();
    if ($u) {
        DriverProfile::where('user_id', $u->id)->forceDelete();
        $u->forceDelete();
    }
}
echo "Done." . PHP_EOL;
```

Run: `php artisan tinker storage/app/smoke_office_onboard.php`

Expected: 3 paths each return 201 + appropriate field values. Path C captures a 6-digit OTP from FakeSmsDriver.

After verifying: `rm storage/app/smoke_office_onboard.php`

---

## Task 7: Office staff verify-phone endpoint (closes the cold-walk-in loop)

**Files:**
- Create: `app/Http/Requests/Driver/VerifyDriverPhoneRequest.php`
- Modify: `app/Http/Controllers/Api/Office/DriverOnboardingController.php` (add `verifyPhone` action)
- Modify: `routes/api.php`

- [ ] **Step 7.1: Create `VerifyDriverPhoneRequest`.**

File: `app/Http/Requests/Driver/VerifyDriverPhoneRequest.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Driver;

use App\Models\PlatformSetting;
use Illuminate\Foundation\Http\FormRequest;

final class VerifyDriverPhoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('office_staff') ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        $codeLength = (int) PlatformSetting::get('otp_code_length', 6);

        return [
            'code' => ['required', 'string', "size:{$codeLength}", 'regex:/^\d+$/'],
        ];
    }
}
```

- [ ] **Step 7.2: Add `verifyPhone` action to controller.**

Edit `app/Http/Controllers/Api/Office/DriverOnboardingController.php` — add imports + action:

Imports (add):
```php
use App\Enums\OtpPurpose;
use App\Http\Requests\Driver\VerifyDriverPhoneRequest;
use App\Models\DriverProfile;
use App\Services\Auth\OtpService;
use Illuminate\Http\Response;
```

Action:
```php
public function verifyPhone(
    VerifyDriverPhoneRequest $request,
    DriverProfile $driverProfile,
    OtpService $otp,
): Response|JsonResponse {
    /** @var User $staff */
    $staff = $request->user();

    if (! $staff->can('manageInOffice', $driverProfile)) {
        return response()->json(['error' => 'wrong_office', 'message' => 'Driver belongs to a different office.'], 403);
    }

    $user = $driverProfile->user;
    if ($user->phone_verified_at !== null) {
        return response()->noContent(); // idempotent
    }

    $verified = $otp->verify(
        $user->phone_number,
        (string) $request->input('code'),
        OtpPurpose::Registration,
    );

    if (! $verified) {
        return response()->json([
            'error'   => 'otp_invalid',
            'message' => 'OTP is invalid or expired.',
        ], 422);
    }

    $user->phone_verified_at = now();
    $user->save();

    return response()->noContent();
}
```

- [ ] **Step 7.3: Wire route.**

File: `routes/api.php` — inside the `office/drivers` group:
```php
    Route::post('{driverProfile}/verify-phone', [DriverOnboardingController::class, 'verifyPhone']);
```

- [ ] **Step 7.4: Smoke test.**

Write to `storage/app/smoke_office_verify_phone.php`:

```php
<?php

declare(strict_types=1);

use App\Models\DriverProfile;
use App\Models\User;
use App\Services\Sms\Drivers\FakeSmsDriver;
use App\Services\Sms\SmsService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;

app()->forgetInstance(SmsService::class);
app()->singleton(SmsService::class, FakeSmsDriver::class);

Cache::flush();
User::where('phone_number', '+218910000320')->forceDelete();

$staff = User::where('phone_number', '+218910000001')->firstOrFail();
$staffToken = $staff->createToken('t')->plainTextToken;
$kernel = app(\Illuminate\Contracts\Http\Kernel::class);
$call = function (string $method, string $uri, array $payload = [], ?string $bearer = null) use ($kernel): TestResponse {
    Auth::forgetGuards();
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    if ($bearer !== null) $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $bearer;
    $req = \Illuminate\Http\Request::create($uri, $method, server: $server, content: (string) json_encode($payload));
    $req->headers->set('Content-Type', 'application/json');
    return new TestResponse($kernel->handle($req));
};

// Cold walk-in to set up state
$resp = $call('POST', '/api/office/drivers/onboard', [
    'phone_number' => '+218910000320',
    'first_name'   => 'VerifyTest',
    'vehicle_type' => 'car',
    'vehicle_plate' => 'V-001',
], $staffToken);
$profileId = $resp->json('driver_profile.id');
$code = app(SmsService::class)->lastCodeFor('+218910000320');

echo "Cold onboard ran. driver_profile.id=" . $profileId . " otp=" . $code . PHP_EOL;

echo PHP_EOL . "1. Wrong code → 422 otp_invalid" . PHP_EOL;
$resp = $call('POST', "/api/office/drivers/{$profileId}/verify-phone", ['code' => '000000'], $staffToken);
echo "  status=" . $resp->status() . " (expected 422)" . PHP_EOL;
echo "  error=" . $resp->json('error') . PHP_EOL;

echo PHP_EOL . "2. Correct code → 204 + phone_verified_at set" . PHP_EOL;
$resp = $call('POST', "/api/office/drivers/{$profileId}/verify-phone", ['code' => $code], $staffToken);
echo "  status=" . $resp->status() . " (expected 204)" . PHP_EOL;
$u = User::where('phone_number', '+218910000320')->firstOrFail();
echo "  phone_verified_at=" . ($u->phone_verified_at?->toIso8601String() ?? 'null (BAD)') . PHP_EOL;

echo PHP_EOL . "3. Replay correct code (already verified) → still 204 (idempotent)" . PHP_EOL;
$resp = $call('POST', "/api/office/drivers/{$profileId}/verify-phone", ['code' => $code], $staffToken);
echo "  status=" . $resp->status() . " (expected 204)" . PHP_EOL;

DriverProfile::where('user_id', $u->id)->forceDelete();
$u->forceDelete();
echo PHP_EOL . "Done." . PHP_EOL;
```

Run + verify, then `rm`.

---

## Task 8: Document upload endpoints

**Files:**
- Create: `app/Http/Requests/Driver/UploadDriverDocumentRequest.php`
- Create: `app/Services/Driver/DriverDocumentService.php`
- Create: `app/Http/Controllers/Api/Office/DriverDocumentController.php`
- Modify: `routes/api.php`

- [ ] **Step 8.1: Create `UploadDriverDocumentRequest`.**

File: `app/Http/Requests/Driver/UploadDriverDocumentRequest.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Driver;

use App\Enums\DriverDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UploadDriverDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('office_staff') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'type'       => ['required', Rule::enum(DriverDocumentType::class)],
            'file'       => ['required', 'file', 'mimes:jpeg,jpg,png,webp,pdf', 'max:10240'], // 10 MB
            'expires_at' => ['nullable', 'date', 'after:today'],
            'notes'      => ['nullable', 'string', 'max:1000'],
        ];
    }
}
```

- [ ] **Step 8.2: Create `DriverDocumentService`.**

File: `app/Services/Driver/DriverDocumentService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Driver;

use App\Enums\DriverDocumentType;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class DriverDocumentService
{
    public function upload(
        DriverProfile $profile,
        DriverDocumentType $type,
        UploadedFile $file,
        ?string $expiresAt,
        ?string $notes,
    ): DriverDocument {
        return DB::transaction(function () use ($profile, $type, $file, $expiresAt, $notes): DriverDocument {
            // Replace any existing file in the single-file collection.
            $user = $profile->user;
            $user->addMedia($file->getRealPath())
                ->usingFileName($file->getClientOriginalName())
                ->toMediaCollection('driver_document_' . $type->value);

            // Upsert metadata row (UNIQUE(driver_id, document_type) ensures
            // at-most-one).
            return DriverDocument::updateOrCreate(
                [
                    'driver_id'     => $user->id,
                    'document_type' => $type->value,
                ],
                [
                    'verified'    => false,
                    'verified_by_admin_id' => null,
                    'verified_at' => null,
                    'expires_at'  => $expiresAt,
                    'notes'       => $notes,
                ],
            );
        });
    }

    public function delete(DriverDocument $document): void
    {
        DB::transaction(function () use ($document): void {
            $user = $document->driver;
            $user->clearMediaCollection('driver_document_' . $document->document_type->value);
            $document->delete();
        });
    }
}
```

- [ ] **Step 8.3: Create `DriverDocumentController`.**

File: `app/Http/Controllers/Api/Office/DriverDocumentController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Office;

use App\Enums\DriverDocumentType;
use App\Enums\DriverErrorCode;
use App\Enums\DriverStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\UploadDriverDocumentRequest;
use App\Http\Resources\DriverDocumentResource;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\User;
use App\Services\Driver\DriverDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final class DriverDocumentController extends Controller
{
    public function __construct(private readonly DriverDocumentService $service) {}

    public function store(UploadDriverDocumentRequest $request, DriverProfile $driverProfile): JsonResponse
    {
        /** @var User $staff */
        $staff = $request->user();

        if (! $staff->can('manageInOffice', $driverProfile)) {
            return response()->json(['error' => 'wrong_office'], 403);
        }
        if ($driverProfile->status !== DriverStatus::PreRegistered) {
            return response()->json([
                'error' => DriverErrorCode::LockedPostSubmission->value,
                'message' => 'Documents are locked once submitted for approval.',
            ], DriverErrorCode::LockedPostSubmission->httpStatus());
        }

        $document = $this->service->upload(
            $driverProfile,
            DriverDocumentType::from((string) $request->input('type')),
            $request->file('file'),
            $request->input('expires_at'),
            $request->input('notes'),
        );

        return response()->json([
            'driver_document' => (new DriverDocumentResource($document))->resolve($request),
        ], 201);
    }

    public function destroy(\Illuminate\Http\Request $request, DriverProfile $driverProfile, DriverDocument $driverDocument): Response|JsonResponse
    {
        /** @var User $staff */
        $staff = $request->user();
        if (! $staff->can('manageInOffice', $driverProfile)) {
            return response()->json(['error' => 'wrong_office'], 403);
        }
        if ($driverProfile->status !== DriverStatus::PreRegistered) {
            return response()->json([
                'error' => DriverErrorCode::LockedPostSubmission->value,
            ], DriverErrorCode::LockedPostSubmission->httpStatus());
        }
        if ($driverDocument->driver_id !== $driverProfile->user_id) {
            return response()->json(['error' => 'mismatch'], 403);
        }

        $this->service->delete($driverDocument);

        return response()->noContent();
    }
}
```

- [ ] **Step 8.4: Wire routes.**

File: `routes/api.php` — add to imports:
```php
use App\Http\Controllers\Api\Office\DriverDocumentController;
```

Inside the `office/drivers` group:
```php
    Route::post('{driverProfile}/documents', [DriverDocumentController::class, 'store']);
    Route::delete('{driverProfile}/documents/{driverDocument}', [DriverDocumentController::class, 'destroy']);
```

- [ ] **Step 8.5: Smoke test (uses `Storage::fake` to avoid real disk writes).**

Write to `storage/app/smoke_doc_upload.php`:

```php
<?php

declare(strict_types=1);

use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

Storage::fake('public');
Cache::flush();
User::where('phone_number', '+218910000330')->forceDelete();

$staff = User::where('phone_number', '+218910000001')->firstOrFail();
$staffToken = $staff->createToken('t')->plainTextToken;
$officeId = $staff->officeStaffAssignments()->whereNull('removed_at')->first()->office_id;

$user = User::create([
    'first_name' => 'DocTest',
    'phone_number' => '+218910000330',
    'password' => 'pass',
    'phone_verified_at' => now(),
    'account_status' => 'active',
]);
$profile = DriverProfile::create([
    'user_id' => $user->id,
    'office_id' => $officeId,
    'status' => 'pre_registered',
    'activity_status' => 'offline',
    'vehicle_type' => 'car',
    'vehicle_plate' => 'D-001',
]);

$kernel = app(\Illuminate\Contracts\Http\Kernel::class);

echo "1. Upload national_id_front" . PHP_EOL;
Auth::forgetGuards();
$file = UploadedFile::fake()->image('id_front.jpg', 800, 500)->size(500);
$req = \Illuminate\Http\Request::create("/api/office/drivers/{$profile->id}/documents", 'POST', [
    'type' => 'national_id_front',
], [], ['file' => $file], [
    'HTTP_ACCEPT' => 'application/json',
    'HTTP_AUTHORIZATION' => 'Bearer ' . $staffToken,
]);
$resp = $kernel->handle($req);
echo "  status=" . $resp->getStatusCode() . " (expected 201)" . PHP_EOL;
echo "  doc count: " . DriverDocument::where('driver_id', $user->id)->count() . " (expected 1)" . PHP_EOL;
echo "  has media: " . ($user->fresh()->getFirstMedia('driver_document_national_id_front') !== null ? 'yes' : 'NO') . PHP_EOL;

echo PHP_EOL . "2. Re-upload national_id_front (single-file collection — replaces)" . PHP_EOL;
Auth::forgetGuards();
$file2 = UploadedFile::fake()->image('id_front_v2.jpg', 800, 500);
$req = \Illuminate\Http\Request::create("/api/office/drivers/{$profile->id}/documents", 'POST', [
    'type' => 'national_id_front',
], [], ['file' => $file2], [
    'HTTP_ACCEPT' => 'application/json',
    'HTTP_AUTHORIZATION' => 'Bearer ' . $staffToken,
]);
$resp = $kernel->handle($req);
echo "  status=" . $resp->getStatusCode() . " (expected 201)" . PHP_EOL;
echo "  doc count after re-upload: " . DriverDocument::where('driver_id', $user->id)->count() . " (expected 1 — same row)" . PHP_EOL;
echo "  user has 1 media in collection: " . $user->fresh()->getMedia('driver_document_national_id_front')->count() . " (expected 1)" . PHP_EOL;

echo PHP_EOL . "3. Upload non-image (PDF) for drivers_license" . PHP_EOL;
Auth::forgetGuards();
$pdf = UploadedFile::fake()->create('license.pdf', 100, 'application/pdf');
$req = \Illuminate\Http\Request::create("/api/office/drivers/{$profile->id}/documents", 'POST', [
    'type' => 'drivers_license',
    'expires_at' => now()->addYears(3)->toDateString(),
], [], ['file' => $pdf], [
    'HTTP_ACCEPT' => 'application/json',
    'HTTP_AUTHORIZATION' => 'Bearer ' . $staffToken,
]);
$resp = $kernel->handle($req);
echo "  status=" . $resp->getStatusCode() . " (expected 201)" . PHP_EOL;

echo PHP_EOL . "4. Reject too-large file (>10 MB)" . PHP_EOL;
Auth::forgetGuards();
$big = UploadedFile::fake()->image('huge.jpg')->size(15 * 1024); // 15 MB
$req = \Illuminate\Http\Request::create("/api/office/drivers/{$profile->id}/documents", 'POST', [
    'type' => 'selfie',
], [], ['file' => $big], [
    'HTTP_ACCEPT' => 'application/json',
    'HTTP_AUTHORIZATION' => 'Bearer ' . $staffToken,
]);
$resp = $kernel->handle($req);
echo "  status=" . $resp->getStatusCode() . " (expected 422 — too large)" . PHP_EOL;

echo PHP_EOL . "5. Delete a document" . PHP_EOL;
Auth::forgetGuards();
$idFrontDoc = DriverDocument::where('driver_id', $user->id)->where('document_type', 'national_id_front')->firstOrFail();
$req = \Illuminate\Http\Request::create("/api/office/drivers/{$profile->id}/documents/{$idFrontDoc->id}", 'DELETE', server: [
    'HTTP_ACCEPT' => 'application/json',
    'HTTP_AUTHORIZATION' => 'Bearer ' . $staffToken,
]);
$resp = $kernel->handle($req);
echo "  status=" . $resp->getStatusCode() . " (expected 204)" . PHP_EOL;
echo "  doc count: " . DriverDocument::where('driver_id', $user->id)->count() . " (expected 1 — only license remains)" . PHP_EOL;

DriverDocument::where('driver_id', $user->id)->delete();
$profile->forceDelete();
$user->forceDelete();
echo PHP_EOL . "Done." . PHP_EOL;
```

Run + verify, then `rm`.

---

## Task 9: Submit for approval

**Files:**
- Modify: `app/Services/Driver/DriverOnboardingService.php` (add `submitForApproval` method)
- Modify: `app/Http/Controllers/Api/Office/DriverOnboardingController.php` (add `submit` action)
- Modify: `routes/api.php`

- [ ] **Step 9.1: Add `submitForApproval` to `DriverOnboardingService`.**

Add method to existing service:

```php
/**
 * Validates required documents are uploaded + phone is verified, then
 * transitions pre_registered → pending_approval.
 *
 * Required documents (universal): national_id_front, national_id_back,
 * drivers_license, selfie, vehicle_registration, vehicle_photo_front,
 * vehicle_photo_back.
 *
 * @return DriverErrorCode|array{driver_profile: \App\Models\DriverProfile}
 */
public function submitForApproval(\App\Models\DriverProfile $profile): DriverErrorCode|array
{
    if ($profile->status !== \App\Enums\DriverStatus::PreRegistered) {
        return DriverErrorCode::InvalidState;
    }
    if ($profile->user->phone_verified_at === null) {
        return DriverErrorCode::PhoneNotVerified;
    }

    $required = [
        'national_id_front', 'national_id_back', 'drivers_license', 'selfie',
        'vehicle_registration', 'vehicle_photo_front', 'vehicle_photo_back',
    ];

    $present = \App\Models\DriverDocument::where('driver_id', $profile->user_id)
        ->pluck('document_type')
        ->map(fn ($t) => $t instanceof \App\Enums\DriverDocumentType ? $t->value : $t)
        ->all();

    $missing = array_values(array_diff($required, $present));
    if ($missing !== []) {
        return DriverErrorCode::MissingDocuments;
    }

    $profile->update(['status' => \App\Enums\DriverStatus::PendingApproval]);

    return ['driver_profile' => $profile->fresh()];
}

/**
 * Helper for callers that want the `missing` array on a MissingDocuments
 * error. Public so the controller can include it in the 422 body.
 *
 * @return array<int, string>
 */
public function missingDocumentsFor(\App\Models\DriverProfile $profile): array
{
    $required = [
        'national_id_front', 'national_id_back', 'drivers_license', 'selfie',
        'vehicle_registration', 'vehicle_photo_front', 'vehicle_photo_back',
    ];
    $present = \App\Models\DriverDocument::where('driver_id', $profile->user_id)
        ->pluck('document_type')
        ->map(fn ($t) => $t instanceof \App\Enums\DriverDocumentType ? $t->value : $t)
        ->all();

    return array_values(array_diff($required, $present));
}
```

- [ ] **Step 9.2: Add `submit` action to controller.**

Edit `DriverOnboardingController`:

```php
public function submit(\Illuminate\Http\Request $request, DriverProfile $driverProfile): JsonResponse
{
    /** @var User $staff */
    $staff = $request->user();
    if (! $staff->can('manageInOffice', $driverProfile)) {
        return response()->json(['error' => 'wrong_office'], 403);
    }

    $result = $this->service->submitForApproval($driverProfile);
    if ($result instanceof \App\Enums\DriverErrorCode) {
        $body = ['error' => $result->value];
        if ($result === \App\Enums\DriverErrorCode::MissingDocuments) {
            $body['missing'] = $this->service->missingDocumentsFor($driverProfile);
            $body['message'] = 'Cannot submit — required documents are missing.';
        } else {
            $body['message'] = match ($result) {
                \App\Enums\DriverErrorCode::PhoneNotVerified => 'Driver\'s phone is not verified yet.',
                \App\Enums\DriverErrorCode::InvalidState => 'Driver is not in a state where they can be submitted.',
                default => 'Submission rejected.',
            };
        }
        return response()->json($body, $result->httpStatus());
    }

    return response()->json([
        'driver_profile' => (new DriverProfileResource($result['driver_profile']))->resolve($request),
    ]);
}
```

- [ ] **Step 9.3: Wire route.**

Inside `office/drivers` group:
```php
    Route::post('{driverProfile}/submit', [DriverOnboardingController::class, 'submit']);
```

- [ ] **Step 9.4: Smoke test happy path + missing-docs path.**

Write to `storage/app/smoke_office_submit.php`:

```php
<?php

declare(strict_types=1);

use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

Storage::fake('public');
Cache::flush();
User::where('phone_number', '+218910000340')->forceDelete();

$staff = User::where('phone_number', '+218910000001')->firstOrFail();
$staffToken = $staff->createToken('t')->plainTextToken;
$officeId = $staff->officeStaffAssignments()->whereNull('removed_at')->first()->office_id;

$user = User::create([
    'first_name' => 'Submit',
    'phone_number' => '+218910000340',
    'password' => 'pass',
    'phone_verified_at' => now(),
    'account_status' => 'active',
]);
$profile = DriverProfile::create([
    'user_id' => $user->id,
    'office_id' => $officeId,
    'status' => 'pre_registered',
    'activity_status' => 'offline',
    'vehicle_type' => 'car',
    'vehicle_plate' => 'S-001',
]);
$kernel = app(\Illuminate\Contracts\Http\Kernel::class);

$callJson = function (string $method, string $uri, array $payload = []) use ($kernel, $staffToken) {
    Auth::forgetGuards();
    $req = \Illuminate\Http\Request::create($uri, $method, server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer ' . $staffToken,
    ], content: (string) json_encode($payload));
    $req->headers->set('Content-Type', 'application/json');
    return new \Illuminate\Testing\TestResponse($kernel->handle($req));
};
$uploadDoc = function (string $type) use ($kernel, $staffToken, $profile) {
    Auth::forgetGuards();
    $file = UploadedFile::fake()->image("{$type}.jpg", 800, 500);
    $req = \Illuminate\Http\Request::create("/api/office/drivers/{$profile->id}/documents", 'POST', [
        'type' => $type,
    ], [], ['file' => $file], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer ' . $staffToken,
    ]);
    $kernel->handle($req);
};

echo "1. Submit with no documents → 422 missing_documents" . PHP_EOL;
$resp = $callJson('POST', "/api/office/drivers/{$profile->id}/submit");
echo "  status=" . $resp->status() . " (expected 422)" . PHP_EOL;
echo "  error=" . $resp->json('error') . PHP_EOL;
echo "  missing count=" . count($resp->json('missing') ?? []) . " (expected 7)" . PHP_EOL;

echo PHP_EOL . "2. Upload all 7 required documents" . PHP_EOL;
foreach ([
    'national_id_front', 'national_id_back', 'drivers_license', 'selfie',
    'vehicle_registration', 'vehicle_photo_front', 'vehicle_photo_back',
] as $type) {
    $uploadDoc($type);
}
echo "  doc count: " . DriverDocument::where('driver_id', $user->id)->count() . " (expected 7)" . PHP_EOL;

echo PHP_EOL . "3. Submit → 200 + status flips to pending_approval" . PHP_EOL;
$resp = $callJson('POST', "/api/office/drivers/{$profile->id}/submit");
echo "  status=" . $resp->status() . " (expected 200)" . PHP_EOL;
echo "  driver_profile.status=" . $resp->json('driver_profile.status') . " (expected pending_approval)" . PHP_EOL;

echo PHP_EOL . "4. Submit again → 409 invalid_state (already pending_approval)" . PHP_EOL;
$resp = $callJson('POST', "/api/office/drivers/{$profile->id}/submit");
echo "  status=" . $resp->status() . " (expected 409)" . PHP_EOL;
echo "  error=" . $resp->json('error') . PHP_EOL;

DriverDocument::where('driver_id', $user->id)->delete();
$profile->forceDelete();
$user->forceDelete();
echo PHP_EOL . "Done." . PHP_EOL;
```

Run + verify + `rm`.

---

## Task 10: Office staff list (queue view)

**Files:**
- Modify: `app/Http/Controllers/Api/Office/DriverOnboardingController.php` (add `index`)
- Modify: `routes/api.php`

- [ ] **Step 10.1: Add `index` action.**

```php
public function index(\Illuminate\Http\Request $request): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
{
    /** @var User $staff */
    $staff = $request->user();
    $officeId = $this->staffOfficeId($staff);

    $statusFilter = $request->input('status');
    $defaultStatuses = ['pre_registered', 'pending_approval'];

    $query = DriverProfile::query()
        ->where('office_id', $officeId)
        ->when($statusFilter !== null,
            fn ($q) => $q->where('status', (string) $statusFilter),
            fn ($q) => $q->whereIn('status', $defaultStatuses),
        )
        ->latest();

    if ($search = $request->input('search')) {
        $query->whereHas('user', fn ($u) => $u->where('phone_number', 'like', '%' . $search . '%')
            ->orWhere('first_name', 'ilike', '%' . $search . '%')
            ->orWhere('last_name', 'ilike', '%' . $search . '%'));
    }

    return DriverProfileResource::collection($query->paginate(25));
}
```

- [ ] **Step 10.2: Wire route.**

Inside `office/drivers` group:
```php
    Route::get('/', [DriverOnboardingController::class, 'index']);
```

- [ ] **Step 10.3: Smoke test.**

Write to `storage/app/smoke_office_index.php` and run. Should return paginated list of drivers tied to staff's office, default-filtered to actionable queue (pre_registered + pending_approval). Verify staff cannot see drivers from other offices.

```php
<?php

declare(strict_types=1);

use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;

User::where('phone_number', '+218910000350')->forceDelete();
User::where('phone_number', '+218910000351')->forceDelete();

$staff = User::where('phone_number', '+218910000001')->firstOrFail();
$staffToken = $staff->createToken('t')->plainTextToken;
$officeId = $staff->officeStaffAssignments()->whereNull('removed_at')->first()->office_id;

$u1 = User::create(['first_name' => 'Q1', 'phone_number' => '+218910000350', 'password' => 'pw', 'phone_verified_at' => now(), 'account_status' => 'active']);
$u2 = User::create(['first_name' => 'Q2', 'phone_number' => '+218910000351', 'password' => 'pw', 'phone_verified_at' => now(), 'account_status' => 'active']);
DriverProfile::create(['user_id' => $u1->id, 'office_id' => $officeId, 'status' => 'pre_registered', 'activity_status' => 'offline', 'vehicle_type' => 'car', 'vehicle_plate' => 'IDX-1']);
DriverProfile::create(['user_id' => $u2->id, 'office_id' => $officeId, 'status' => 'active', 'activity_status' => 'offline', 'vehicle_type' => 'car', 'vehicle_plate' => 'IDX-2']);

$kernel = app(\Illuminate\Contracts\Http\Kernel::class);
$call = function (string $uri) use ($kernel, $staffToken): TestResponse {
    Auth::forgetGuards();
    $req = \Illuminate\Http\Request::create($uri, 'GET', server: [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer ' . $staffToken,
    ]);
    return new TestResponse($kernel->handle($req));
};

echo "1. Default index — only actionable queue" . PHP_EOL;
$resp = $call('/api/office/drivers');
$plates = collect($resp->json('data'))->pluck('vehicle_plate')->all();
echo "  plates returned: " . implode(',', $plates) . " (expected IDX-1 only — IDX-2 active is hidden)" . PHP_EOL;

echo PHP_EOL . "2. Filter by status=active" . PHP_EOL;
$resp = $call('/api/office/drivers?status=active');
$plates = collect($resp->json('data'))->pluck('vehicle_plate')->all();
echo "  plates returned: " . implode(',', $plates) . " (expected IDX-2)" . PHP_EOL;

DriverProfile::where('user_id', $u1->id)->forceDelete();
DriverProfile::where('user_id', $u2->id)->forceDelete();
$u1->forceDelete();
$u2->forceDelete();
echo PHP_EOL . "Done." . PHP_EOL;
```

Run + verify + `rm`.

---

## Task 11: Admin approve

**Files:**
- Create: `app/Services/Driver/DriverApprovalService.php`
- Create: `app/Http/Controllers/Api/Admin/DriverController.php` (start — `approve`)
- Modify: `routes/api.php`

- [ ] **Step 11.1: Create `DriverApprovalService`.**

File: `app/Services/Driver/DriverApprovalService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Driver;

use App\Enums\DriverErrorCode;
use App\Enums\DriverStatus;
use App\Models\DriverAccount;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DriverApprovalService
{
    /**
     * Atomic approval: state flip + driver_account creation + role assignment +
     * docs verified. Either everything happens or nothing does.
     */
    public function approve(DriverProfile $profile, User $admin): DriverErrorCode|DriverProfile
    {
        if ($profile->status !== DriverStatus::PendingApproval) {
            return DriverErrorCode::InvalidState;
        }

        return DB::transaction(function () use ($profile, $admin): DriverProfile {
            // 1. State + audit
            $profile->update([
                'status'               => DriverStatus::Active,
                'approved_at'          => now(),
                'approved_by_admin_id' => $admin->id,
            ]);

            // 2. Driver account (3 buckets)
            DriverAccount::firstOrCreate(
                ['driver_id' => $profile->user_id],
                [
                    'cash_to_deposit'   => '0.00',
                    'earnings_balance'  => '0.00',
                    'debt_balance'      => '0.00',
                    'max_cash_liability' => PlatformSetting::get('new_driver_max_liability', '100.00'),
                ],
            );

            // 3. Spatie role
            $profile->user->assignRole('driver');

            // 4. Mark all docs verified
            DriverDocument::where('driver_id', $profile->user_id)
                ->update([
                    'verified'             => true,
                    'verified_by_admin_id' => $admin->id,
                    'verified_at'          => now(),
                ]);

            return $profile->fresh(['user', 'office']);
        });
    }
}
```

- [ ] **Step 11.2: Create `Admin\DriverController` with `approve` action.**

File: `app/Http/Controllers/Api/Admin/DriverController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Enums\DriverErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Resources\DriverProfileFullResource;
use App\Models\DriverProfile;
use App\Models\User;
use App\Services\Driver\DriverApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DriverController extends Controller
{
    public function __construct(private readonly DriverApprovalService $approvalService) {}

    public function approve(Request $request, DriverProfile $driverProfile): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $result = $this->approvalService->approve($driverProfile, $admin);

        if ($result instanceof DriverErrorCode) {
            return response()->json([
                'error'   => $result->value,
                'message' => 'Driver is not in pending_approval state.',
            ], $result->httpStatus());
        }

        return response()->json([
            'driver_profile' => (new DriverProfileFullResource($result))->resolve($request),
        ]);
    }
}
```

- [ ] **Step 11.3: Wire route.**

File: `routes/api.php` — add to imports:
```php
use App\Http\Controllers\Api\Admin\DriverController as AdminDriverController;
```

Add new route group:
```php
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/drivers')->group(function (): void {
    Route::post('{driverProfile}/approve', [AdminDriverController::class, 'approve']);
});
```

- [ ] **Step 11.4: Smoke test.**

Write to `storage/app/smoke_admin_approve.php`:

```php
<?php

declare(strict_types=1);

use App\Models\DriverAccount;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

Cache::flush();
User::where('phone_number', '+218910000360')->forceDelete();

$admin = User::where('phone_number', '+218910000002')->firstOrFail();
$adminToken = $admin->createToken('t')->plainTextToken;
$officeId = \App\Models\OfficeLocation::where('is_active', true)->value('id');

$user = User::create([
    'first_name' => 'Approve',
    'phone_number' => '+218910000360',
    'password' => 'pw',
    'phone_verified_at' => now(),
    'account_status' => 'active',
]);
$user->assignRole('user');
$profile = DriverProfile::create([
    'user_id' => $user->id,
    'office_id' => $officeId,
    'status' => 'pending_approval',
    'activity_status' => 'offline',
    'vehicle_type' => 'car',
    'vehicle_plate' => 'A-001',
]);
foreach (['national_id_front', 'national_id_back', 'drivers_license', 'selfie',
          'vehicle_registration', 'vehicle_photo_front', 'vehicle_photo_back'] as $t) {
    DriverDocument::create([
        'driver_id' => $user->id,
        'document_type' => $t,
        'verified' => false,
    ]);
}

$kernel = app(\Illuminate\Contracts\Http\Kernel::class);
$call = function (string $uri) use ($kernel, $adminToken): TestResponse {
    Auth::forgetGuards();
    $req = \Illuminate\Http\Request::create($uri, 'POST', server: [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer ' . $adminToken,
    ]);
    return new TestResponse($kernel->handle($req));
};

echo "1. Approve" . PHP_EOL;
$resp = $call("/api/admin/drivers/{$profile->id}/approve");
echo "  status=" . $resp->status() . " (expected 200)" . PHP_EOL;
echo "  driver_profile.status=" . $resp->json('driver_profile.status') . " (expected active)" . PHP_EOL;

echo PHP_EOL . "Side effects:" . PHP_EOL;
$user->refresh();
echo "  user has 'driver' role: " . ($user->hasRole('driver') ? 'yes' : 'NO') . PHP_EOL;
$account = DriverAccount::where('driver_id', $user->id)->first();
echo "  driver_account exists: " . ($account ? 'yes' : 'NO') . PHP_EOL;
echo "  max_cash_liability: " . ($account?->max_cash_liability ?? '-') . " (expected 100.00)" . PHP_EOL;
echo "  documents all verified: " . (DriverDocument::where('driver_id', $user->id)->where('verified', false)->count() === 0 ? 'yes' : 'NO') . PHP_EOL;

echo PHP_EOL . "2. Approve again → 409 invalid_state" . PHP_EOL;
$resp = $call("/api/admin/drivers/{$profile->id}/approve");
echo "  status=" . $resp->status() . " (expected 409)" . PHP_EOL;

DriverDocument::where('driver_id', $user->id)->delete();
DriverAccount::where('driver_id', $user->id)->delete();
$profile->forceDelete();
$user->forceDelete();
echo PHP_EOL . "Done." . PHP_EOL;
```

Run + verify + `rm`.

---

## Task 12: Admin reject / suspend / reinstate

**Files:**
- Create: `app/Services/Driver/DriverStatusTransitionService.php`
- Modify: `app/Http/Controllers/Api/Admin/DriverController.php` (add three actions)
- Modify: `routes/api.php`

- [ ] **Step 12.1: Create `DriverStatusTransitionService`.**

File: `app/Services/Driver/DriverStatusTransitionService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Driver;

use App\Enums\DriverErrorCode;
use App\Enums\DriverStatus;
use App\Models\DriverProfile;

final class DriverStatusTransitionService
{
    /** @var array<string, array<int, DriverStatus>> Allowed transitions */
    private const ALLOWED = [
        'pending_approval' => [DriverStatus::Rejected],
        'active' => [DriverStatus::Suspended],
        'suspended' => [DriverStatus::Active],
    ];

    public function reject(DriverProfile $profile): DriverErrorCode|DriverProfile
    {
        return $this->transition($profile, DriverStatus::Rejected, ['rejected_at' => now()]);
    }

    public function suspend(DriverProfile $profile): DriverErrorCode|DriverProfile
    {
        return $this->transition($profile, DriverStatus::Suspended);
    }

    public function reinstate(DriverProfile $profile): DriverErrorCode|DriverProfile
    {
        return $this->transition($profile, DriverStatus::Active);
    }

    private function transition(DriverProfile $profile, DriverStatus $to, array $extraColumns = []): DriverErrorCode|DriverProfile
    {
        $allowed = self::ALLOWED[$profile->status->value] ?? [];
        if (! in_array($to, $allowed, true)) {
            return DriverErrorCode::InvalidState;
        }

        $profile->update(array_merge(['status' => $to], $extraColumns));

        return $profile->fresh();
    }
}
```

- [ ] **Step 12.2: Add three actions to `Admin\DriverController`.**

Edit existing controller:

Add import:
```php
use App\Services\Driver\DriverStatusTransitionService;
```

Replace the constructor to also inject the transition service:
```php
public function __construct(
    private readonly DriverApprovalService $approvalService,
    private readonly DriverStatusTransitionService $transitionService,
) {}
```

Add actions:
```php
public function reject(Request $request, DriverProfile $driverProfile): JsonResponse
{
    return $this->respondWithTransition($request, $this->transitionService->reject($driverProfile));
}

public function suspend(Request $request, DriverProfile $driverProfile): JsonResponse
{
    return $this->respondWithTransition($request, $this->transitionService->suspend($driverProfile));
}

public function reinstate(Request $request, DriverProfile $driverProfile): JsonResponse
{
    return $this->respondWithTransition($request, $this->transitionService->reinstate($driverProfile));
}

private function respondWithTransition(Request $request, DriverErrorCode|DriverProfile $result): JsonResponse
{
    if ($result instanceof DriverErrorCode) {
        return response()->json([
            'error'   => $result->value,
            'message' => 'Transition not allowed from current state.',
        ], $result->httpStatus());
    }

    return response()->json([
        'driver_profile' => (new \App\Http\Resources\DriverProfileResource($result))->resolve($request),
    ]);
}
```

- [ ] **Step 12.3: Wire routes.**

Inside the `admin/drivers` group:
```php
    Route::post('{driverProfile}/reject', [AdminDriverController::class, 'reject']);
    Route::post('{driverProfile}/suspend', [AdminDriverController::class, 'suspend']);
    Route::post('{driverProfile}/reinstate', [AdminDriverController::class, 'reinstate']);
```

- [ ] **Step 12.4: Smoke test all three transitions.**

Write to `storage/app/smoke_admin_transitions.php` covering: reject from pending_approval (→ rejected), suspend from active (→ suspended), reinstate from suspended (→ active), invalid_state for wrong-direction transitions.

```php
<?php

declare(strict_types=1);

use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;

User::where('phone_number', '+218910000370')->forceDelete();

$admin = User::where('phone_number', '+218910000002')->firstOrFail();
$adminToken = $admin->createToken('t')->plainTextToken;
$officeId = \App\Models\OfficeLocation::where('is_active', true)->value('id');
$user = User::create(['first_name' => 'Trans', 'phone_number' => '+218910000370', 'password' => 'pw', 'phone_verified_at' => now(), 'account_status' => 'active']);

$kernel = app(\Illuminate\Contracts\Http\Kernel::class);
$call = function (string $uri) use ($kernel, $adminToken): TestResponse {
    Auth::forgetGuards();
    $req = \Illuminate\Http\Request::create($uri, 'POST', server: [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer ' . $adminToken,
    ]);
    return new TestResponse($kernel->handle($req));
};

// REJECT: pending_approval → rejected
$profile = DriverProfile::create(['user_id' => $user->id, 'office_id' => $officeId, 'status' => 'pending_approval', 'activity_status' => 'offline', 'vehicle_type' => 'car', 'vehicle_plate' => 'T-001']);
$resp = $call("/api/admin/drivers/{$profile->id}/reject");
echo "Reject: status=" . $resp->status() . " new=" . $resp->json('driver_profile.status') . " (expected 200, rejected)" . PHP_EOL;
$profile->forceDelete();

// SUSPEND: active → suspended
$profile = DriverProfile::create(['user_id' => $user->id, 'office_id' => $officeId, 'status' => 'active', 'activity_status' => 'offline', 'vehicle_type' => 'car', 'vehicle_plate' => 'T-002']);
$resp = $call("/api/admin/drivers/{$profile->id}/suspend");
echo "Suspend: status=" . $resp->status() . " new=" . $resp->json('driver_profile.status') . " (expected 200, suspended)" . PHP_EOL;

// REINSTATE: suspended → active
$resp = $call("/api/admin/drivers/{$profile->id}/reinstate");
echo "Reinstate: status=" . $resp->status() . " new=" . $resp->json('driver_profile.status') . " (expected 200, active)" . PHP_EOL;

// Invalid: try to suspend a rejected profile
$profile->update(['status' => 'rejected']);
$resp = $call("/api/admin/drivers/{$profile->id}/suspend");
echo "Suspend rejected: status=" . $resp->status() . " (expected 409)" . PHP_EOL;

$profile->forceDelete();
$user->forceDelete();
echo "Done." . PHP_EOL;
```

Run + verify + `rm`.

---

## Task 13: Admin list / show

**Files:**
- Modify: `app/Http/Controllers/Api/Admin/DriverController.php` (add `index` + `show`)
- Modify: `routes/api.php`

- [ ] **Step 13.1: Add `index` and `show` actions.**

```php
public function index(Request $request)
{
    $query = DriverProfile::query()
        ->with(['user', 'office'])
        ->when($request->input('status'), fn ($q, $s) => $q->where('status', (string) $s))
        ->when($request->input('office_id'), fn ($q, $o) => $q->where('office_id', (int) $o))
        ->orderByRaw("CASE status WHEN 'pending_approval' THEN 0 ELSE 1 END")
        ->oldest();

    if ($search = $request->input('search')) {
        $query->whereHas('user', fn ($u) =>
            $u->where('phone_number', 'like', '%' . $search . '%')
              ->orWhere('first_name', 'ilike', '%' . $search . '%')
              ->orWhere('last_name', 'ilike', '%' . $search . '%')
        );
    }

    return \App\Http\Resources\DriverProfileResource::collection($query->paginate(25));
}

public function show(Request $request, DriverProfile $driverProfile): JsonResponse
{
    $driverProfile->load(['user', 'office']);

    return response()->json([
        'driver_profile' => (new DriverProfileFullResource($driverProfile))->resolve($request),
    ]);
}
```

- [ ] **Step 13.2: Wire routes.**

```php
    Route::get('/', [AdminDriverController::class, 'index']);
    Route::get('{driverProfile}', [AdminDriverController::class, 'show']);
```

- [ ] **Step 13.3: Smoke test (brief — already covered by other tasks, just confirm the endpoints register and return JSON).**

```bash
php artisan tinker --execute="
\$admin = App\Models\User::where('phone_number', '+218910000002')->first();
\$token = \$admin->createToken('t')->plainTextToken;
\$kernel = app(\Illuminate\Contracts\Http\Kernel::class);
\Illuminate\Support\Facades\Auth::forgetGuards();
\$req = \Illuminate\Http\Request::create('/api/admin/drivers', 'GET', server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . \$token]);
\$resp = \$kernel->handle(\$req);
echo \$resp->getStatusCode() . PHP_EOL;
echo substr(\$resp->getContent(), 0, 200) . '...' . PHP_EOL;
"
```
Expected: status 200, JSON body with `data` and `meta` keys (Laravel paginator shape).

---

## Task 14: Driver self-service — region preferences

**Files:**
- Create: `app/Http/Requests/Driver/UpdateRegionsRequest.php`
- Create: `app/Services/Driver/DriverRegionService.php`
- Create: `app/Http/Controllers/Api/Driver/RegionController.php`
- Modify: `routes/api.php`

- [ ] **Step 14.1: Create `UpdateRegionsRequest`.**

File: `app/Http/Requests/Driver/UpdateRegionsRequest.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Driver;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateRegionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        return $user !== null && $user->hasRole('driver');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'region_ids'   => ['present', 'array'],
            'region_ids.*' => ['integer', 'exists:regions,id'],
        ];
    }
}
```

- [ ] **Step 14.2: Create `DriverRegionService`.**

File: `app/Services/Driver/DriverRegionService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Driver;

use App\Enums\DriverErrorCode;
use App\Models\DriverProfile;
use App\Models\Region;
use Illuminate\Database\Eloquent\Collection;

final class DriverRegionService
{
    /**
     * Returns the regions available within the driver's office service area.
     */
    public function availableForDriver(DriverProfile $profile): Collection
    {
        $office = $profile->office;
        if ($office === null || $office->region_id === null) {
            return new Collection();
        }
        $serviceAreaId = $office->region->service_area_id ?? null;
        if ($serviceAreaId === null) {
            return new Collection();
        }

        return Region::query()
            ->where('service_area_id', $serviceAreaId)
            ->where('is_active', true)
            ->get();
    }

    /**
     * Sync the driver's region selection. Empty array = "all available".
     * Validates every region_id is within the driver's office service area.
     *
     * @param  array<int, int>  $regionIds
     * @return DriverErrorCode|Collection<int, Region>
     */
    public function update(DriverProfile $profile, array $regionIds): DriverErrorCode|Collection
    {
        $available = $this->availableForDriver($profile);
        $availableIds = $available->pluck('id')->all();

        foreach ($regionIds as $id) {
            if (! in_array((int) $id, $availableIds, true)) {
                return DriverErrorCode::OutsideServiceArea;
            }
        }

        $profile->user->driverRegions()->sync($regionIds);

        return Region::whereIn('id', $regionIds)->get();
    }
}
```

- [ ] **Step 14.3: Create `RegionController`.**

File: `app/Http/Controllers/Api/Driver/RegionController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Driver;

use App\Enums\DriverErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\UpdateRegionsRequest;
use App\Http\Resources\RegionResource;
use App\Models\User;
use App\Services\Driver\DriverRegionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RegionController extends Controller
{
    public function __construct(private readonly DriverRegionService $service) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $user->driverProfile;
        if ($profile === null) {
            return response()->json(['error' => 'no_driver_profile'], 404);
        }

        $available = $this->service->availableForDriver($profile);
        $selected = $user->driverRegions()->get();
        $effective = $selected->isEmpty() ? $available : $selected;

        return response()->json([
            'office_id'   => $profile->office_id,
            'office_name' => $profile->office?->name,
            'available'   => RegionResource::collection($available)->resolve($request),
            'selected'    => RegionResource::collection($selected)->resolve($request),
            'effective'   => RegionResource::collection($effective)->resolve($request),
        ]);
    }

    public function update(UpdateRegionsRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $user->driverProfile;
        if ($profile === null) {
            return response()->json(['error' => 'no_driver_profile'], 404);
        }

        $regionIds = array_map('intval', (array) $request->input('region_ids', []));
        $result = $this->service->update($profile, $regionIds);

        if ($result instanceof DriverErrorCode) {
            return response()->json([
                'error'   => $result->value,
                'message' => 'One or more regions are outside your office service area.',
            ], $result->httpStatus());
        }

        // Return same shape as index for client consistency
        return $this->index($request);
    }
}
```

- [ ] **Step 14.4: Wire routes.**

Add to imports:
```php
use App\Http\Controllers\Api\Driver\RegionController;
```

Add new group:
```php
Route::middleware(['auth:sanctum', 'role:driver'])->prefix('driver')->group(function (): void {
    Route::get('regions',   [RegionController::class, 'index']);
    Route::patch('regions', [RegionController::class, 'update']);
});
```

- [ ] **Step 14.5: Smoke test.**

Write a smoke test that creates an active driver, verifies `index` returns available regions + empty selected, then `update` with a valid region succeeds + updates selected, then `update` with a region outside the service area returns 422.

```php
<?php
declare(strict_types=1);

use App\Models\DriverProfile;
use App\Models\OfficeLocation;
use App\Models\Region;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;

User::where('phone_number', '+218910000380')->forceDelete();

$user = User::create([
    'first_name' => 'RegTest',
    'phone_number' => '+218910000380',
    'password' => 'pw',
    'phone_verified_at' => now(),
    'account_status' => 'active',
]);
$user->assignRole(['user', 'driver']);

$office = OfficeLocation::with('region.serviceArea')->where('is_active', true)->firstOrFail();
$profile = DriverProfile::create([
    'user_id' => $user->id,
    'office_id' => $office->id,
    'status' => 'active',
    'activity_status' => 'offline',
    'vehicle_type' => 'car',
    'vehicle_plate' => 'R-001',
]);

$token = $user->createToken('t')->plainTextToken;
$kernel = app(\Illuminate\Contracts\Http\Kernel::class);
$call = function (string $method, string $uri, array $payload = []) use ($kernel, $token): TestResponse {
    Auth::forgetGuards();
    $req = \Illuminate\Http\Request::create($uri, $method, server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
    ], content: (string) json_encode($payload));
    $req->headers->set('Content-Type', 'application/json');
    return new TestResponse($kernel->handle($req));
};

$resp = $call('GET', '/api/driver/regions');
echo "1. GET /regions status=" . $resp->status() . PHP_EOL;
echo "   available count=" . count($resp->json('available')) . PHP_EOL;
echo "   selected count=" . count($resp->json('selected')) . " (expected 0)" . PHP_EOL;
echo "   effective count=" . count($resp->json('effective')) . " (= available, since selected is empty)" . PHP_EOL;

$availableIds = collect($resp->json('available'))->pluck('id')->all();
if (count($availableIds) > 0) {
    $resp = $call('PATCH', '/api/driver/regions', ['region_ids' => [$availableIds[0]]]);
    echo PHP_EOL . "2. PATCH selecting one region status=" . $resp->status() . PHP_EOL;
    echo "   selected count=" . count($resp->json('selected')) . " (expected 1)" . PHP_EOL;
}

$resp = $call('PATCH', '/api/driver/regions', ['region_ids' => [99999]]);
echo PHP_EOL . "3. PATCH with non-existent region_id status=" . $resp->status() . " (expected 422 — exists rule fails)" . PHP_EOL;

$user->driverRegions()->detach();
$profile->forceDelete();
$user->forceDelete();
echo "Done." . PHP_EOL;
```

---

## Task 15: Driver self-service — profile + account view

**Files:**
- Create: `app/Http/Controllers/Api/Driver/ProfileController.php`
- Create: `app/Http/Controllers/Api/Driver/AccountController.php`
- Modify: `routes/api.php`

- [ ] **Step 15.1: `ProfileController`.**

File: `app/Http/Controllers/Api/Driver/ProfileController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriverProfileResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProfileController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $user->driverProfile;
        if ($profile === null) {
            return response()->json(['error' => 'no_driver_profile'], 404);
        }

        return response()->json([
            'driver_profile' => (new DriverProfileResource($profile))->resolve($request),
        ]);
    }
}
```

- [ ] **Step 15.2: `AccountController`.**

File: `app/Http/Controllers/Api/Driver/AccountController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriverAccountResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AccountController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $account = $user->driverAccount;
        if ($account === null) {
            return response()->json(['error' => 'no_driver_account'], 404);
        }
        $transactions = $user->driverAccountTransactions()
            ->latest()
            ->limit(30)
            ->get(['id', 'bucket', 'amount', 'reason', 'balance_after', 'created_at']);

        return response()->json([
            'account'      => (new DriverAccountResource($account))->resolve($request),
            'transactions' => $transactions,
        ]);
    }
}
```

- [ ] **Step 15.3: Wire routes.**

Add to imports:
```php
use App\Http\Controllers\Api\Driver\AccountController;
use App\Http\Controllers\Api\Driver\ProfileController as DriverProfileViewController;
```

Inside the `driver` group:
```php
    Route::get('profile', DriverProfileViewController::class);
    Route::get('account', AccountController::class);
```

- [ ] **Step 15.4: Smoke test (brief — same setup as task 14).**

```bash
php artisan tinker --execute="
\$u = App\Models\User::factory()->create(['phone_verified_at' => now()]);
\$u->assignRole(['user', 'driver']);
\$office = App\Models\OfficeLocation::where('is_active', true)->first();
App\Models\DriverProfile::create(['user_id' => \$u->id, 'office_id' => \$office->id, 'status' => 'active', 'activity_status' => 'offline', 'vehicle_type' => 'car', 'vehicle_plate' => 'X']);
App\Models\DriverAccount::create(['driver_id' => \$u->id, 'cash_to_deposit' => 0, 'earnings_balance' => 0, 'debt_balance' => 0, 'max_cash_liability' => 100]);
\$t = \$u->createToken('t')->plainTextToken;
\$k = app(\Illuminate\Contracts\Http\Kernel::class);
\Illuminate\Support\Facades\Auth::forgetGuards();
\$req = \Illuminate\Http\Request::create('/api/driver/profile', 'GET', server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . \$t]);
echo 'profile: ' . \$k->handle(\$req)->getStatusCode() . PHP_EOL;
\Illuminate\Support\Facades\Auth::forgetGuards();
\$req = \Illuminate\Http\Request::create('/api/driver/account', 'GET', server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . \$t]);
echo 'account: ' . \$k->handle(\$req)->getStatusCode() . PHP_EOL;
\$u->driverProfile->forceDelete();
\$u->driverAccount->delete();
\$u->forceDelete();
"
```
Expected: profile 200, account 200.

---

## Task 16: End-to-end + Pint + docs update

- [ ] **Step 16.1: End-to-end smoke test.**

Write `storage/app/smoke_e2e_driver_onboarding.php` exercising the full happy-path: cold walk-in → OTP verify → 7 documents uploaded → submit → admin approve → check side effects → driver picks regions. Should finish with all green.

```php
<?php

declare(strict_types=1);

use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\User;
use App\Services\Sms\Drivers\FakeSmsDriver;
use App\Services\Sms\SmsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

Storage::fake('public');
app()->forgetInstance(SmsService::class);
app()->singleton(SmsService::class, FakeSmsDriver::class);
$sms = app(SmsService::class);

Cache::flush();
User::where('phone_number', '+218910000400')->forceDelete();

$staff = User::where('phone_number', '+218910000001')->firstOrFail();
$admin = User::where('phone_number', '+218910000002')->firstOrFail();
$staffToken = $staff->createToken('t')->plainTextToken;
$adminToken = $admin->createToken('t')->plainTextToken;
$kernel = app(\Illuminate\Contracts\Http\Kernel::class);

$callJson = function (string $method, string $uri, array $payload, string $bearer) use ($kernel) {
    Auth::forgetGuards();
    $req = \Illuminate\Http\Request::create($uri, $method, server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer ' . $bearer,
    ], content: (string) json_encode($payload));
    $req->headers->set('Content-Type', 'application/json');
    return new \Illuminate\Testing\TestResponse($kernel->handle($req));
};

echo "1. Cold walk-in onboard" . PHP_EOL;
$resp = $callJson('POST', '/api/office/drivers/onboard', [
    'phone_number' => '+218910000400',
    'first_name' => 'E2E',
    'last_name' => 'Driver',
    'vehicle_type' => 'car',
    'vehicle_plate' => 'E2E-001',
], $staffToken);
$profileId = $resp->json('driver_profile.id');
echo "  profileId=$profileId otp_required=" . var_export($resp->json('otp_required'), true) . PHP_EOL;

echo "2. Verify in-office OTP" . PHP_EOL;
$code = $sms->lastCodeFor('+218910000400');
$resp = $callJson('POST', "/api/office/drivers/{$profileId}/verify-phone", ['code' => $code], $staffToken);
echo "  status=" . $resp->status() . PHP_EOL;

echo "3. Upload 7 docs" . PHP_EOL;
foreach (['national_id_front', 'national_id_back', 'drivers_license', 'selfie',
          'vehicle_registration', 'vehicle_photo_front', 'vehicle_photo_back'] as $type) {
    Auth::forgetGuards();
    $file = UploadedFile::fake()->image("{$type}.jpg", 800, 500);
    $req = \Illuminate\Http\Request::create("/api/office/drivers/{$profileId}/documents", 'POST', [
        'type' => $type,
    ], [], ['file' => $file], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer ' . $staffToken,
    ]);
    $kernel->handle($req);
}
$user = User::where('phone_number', '+218910000400')->firstOrFail();
echo "  doc count: " . DriverDocument::where('driver_id', $user->id)->count() . PHP_EOL;

echo "4. Submit" . PHP_EOL;
$resp = $callJson('POST', "/api/office/drivers/{$profileId}/submit", [], $staffToken);
echo "  status=" . $resp->status() . " new=" . $resp->json('driver_profile.status') . PHP_EOL;

echo "5. Approve" . PHP_EOL;
$resp = $callJson('POST', "/api/admin/drivers/{$profileId}/approve", [], $adminToken);
echo "  status=" . $resp->status() . " new=" . $resp->json('driver_profile.status') . PHP_EOL;
$user->refresh();
echo "  has driver role: " . ($user->hasRole('driver') ? 'yes' : 'NO') . PHP_EOL;
echo "  driver_account exists: " . ($user->driverAccount ? 'yes' : 'NO') . PHP_EOL;

echo "6. Driver picks regions (login as driver)" . PHP_EOL;
$driverToken = $user->createToken('t')->plainTextToken;
$resp = $callJson('GET', '/api/driver/regions', [], $driverToken);
echo "  available count=" . count($resp->json('available')) . PHP_EOL;
$ids = collect($resp->json('available'))->pluck('id')->all();
if ($ids) {
    $resp = $callJson('PATCH', '/api/driver/regions', ['region_ids' => [$ids[0]]], $driverToken);
    echo "  selected count after patch=" . count($resp->json('selected')) . PHP_EOL;
}

echo PHP_EOL . "Cleanup" . PHP_EOL;
DriverDocument::where('driver_id', $user->id)->delete();
$user->driverAccount?->delete();
$user->driverRegions()->detach();
DriverProfile::where('id', $profileId)->forceDelete();
$user->forceDelete();
echo "Done." . PHP_EOL;
```

Run + verify → should output "Done." with all status codes 200/201/204 as expected.

After verifying: `rm storage/app/smoke_e2e_driver_onboarding.php`.

- [ ] **Step 16.2: Pint pass on the auth-related files only.**

```bash
vendor/bin/pint app/Enums/DriverErrorCode.php app/Policies app/Services/Driver app/Http/Controllers/Api/Me/Driver app/Http/Controllers/Api/Office app/Http/Controllers/Api/Admin app/Http/Controllers/Api/Driver app/Http/Requests/Driver app/Http/Resources/DriverProfileResource.php app/Http/Resources/DriverProfileFullResource.php app/Http/Resources/DriverDocumentResource.php app/Http/Resources/DriverAccountResource.php app/Http/Resources/RegionResource.php database/seeders/TestStaffSeeder.php database/seeders/DatabaseSeeder.php routes/api.php app/Models/User.php
```

Expected: a list of "fixed" files. Re-run the E2E smoke test (step 16.1) afterwards to confirm Pint didn't break anything.

- [ ] **Step 16.3: Update `docs/CLAUDE.md` "Current Project State" — add the driver onboarding milestone row + endpoint table.**

Mirror the auth milestone style. Update the "Last updated" date. Move "Driver onboarding" from "next steps" to "complete." Surface the locked decisions and any gotchas.

- [ ] **Step 16.4: Update `docs/SYSTEM_SPECIFICATION.md` — append a new §17.5 (or wherever the auth milestone lives) for driver onboarding. Mirror the auth milestone format.**

- [ ] **Step 16.5: Mark spec doc as implemented.**

In `docs/superpowers/specs/2026-05-07-driver-onboarding-design.md`, change the front matter:

```diff
-**Status:** Approved (post-brainstorm)
+**Status:** ✅ Implemented (2026-05-07)
```

---

**End of plan.**

## Approximate test footprint after this milestone

- ~10 tinker smoke tests (run + cleanup) covering: pre-registration, lookup, all 3 onboarding paths, verify-phone, document upload + replace + delete, submit happy + missing-docs, approve + side effects, reject/suspend/reinstate, region picking, end-to-end.
- All endpoints exercised manually before this milestone is considered done.
- Pest test promotion (deferred to test-DB pre-flight milestone, same as auth milestone).
