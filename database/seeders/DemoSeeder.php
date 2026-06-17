<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AccountStatus;
use App\Enums\DeliveryFeePayer;
use App\Enums\DeliveryFeeStatus;
use App\Enums\DriverActivityStatus;
use App\Enums\DriverStatus;
use App\Enums\MerchantStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\VehicleType;
use App\Models\DriverAccount;
use App\Models\DriverProfile;
use App\Models\MerchantProfile;
use App\Models\OfficeLocation;
use App\Models\OfficeStaffAssignment;
use App\Models\Order;
use App\Models\Region;
use App\Models\ServiceArea;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\LineString;
use Clickbar\Magellan\Data\Geometries\Point;
use Clickbar\Magellan\Data\Geometries\Polygon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Dev-only demo world for manual API testing (Postman) and the dashboard/apps.
 *
 * Bootstraps the platform skeleton that can't be created through the API
 * (PostGIS geography + a first admin) plus known-credential accounts and a
 * handful of orders in different states, so every endpoint is exercisable
 * immediately.
 *
 * Run: `php artisan db:seed --class=Database\Seeders\DemoSeeder`
 * (or `php artisan migrate:fresh --seed` — DatabaseSeeder calls it in local/dev).
 *
 * All demo accounts use password **password**. Phone scheme: +21891000XXXX.
 * Skipped in production (gated in DatabaseSeeder by APP_ENV).
 *
 * NOTE: public_id is set explicitly everywhere — DatabaseSeeder runs with
 * `WithoutModelEvents`, which mutes the `booted()` hooks that normally generate it.
 */
final class DemoSeeder extends Seeder
{
    private const PASSWORD = 'password';

    public function run(): void
    {
        [$region, $office] = $this->seedGeography();

        $admin = $this->account('+218910001000', 'Demo', 'Admin', 'admin');
        $staff = $this->account('+218910001001', 'Demo', 'Staff', 'office_staff');
        OfficeStaffAssignment::firstOrCreate(
            ['user_id' => $staff->id, 'office_id' => $office->id],
            ['public_id' => (string) Str::ulid(), 'is_manager' => true, 'assigned_at' => now()],
        );

        $driverA = $this->onlineDriver('+218910001010', 'Driver', 'One', $office, VehicleType::Car);
        $this->onlineDriver('+218910001011', 'Driver', 'Two', $office, VehicleType::Motorcycle);

        $customer1 = $this->account('+218910001020', 'Customer', 'One', 'user');
        $customer2 = $this->account('+218910001021', 'Customer', 'Two', 'user');
        $seller = $this->account('+218910001022', 'Seller', 'Sami', 'user');

        $merchantUser = $this->account('+218910001030', 'Acme', 'Owner', 'merchant');
        $merchant = MerchantProfile::firstOrCreate(
            ['user_id' => $merchantUser->id],
            [
                'public_id' => (string) Str::ulid(),
                'business_name' => 'Acme Store',
                'business_phone' => '+218910001030',
                'status' => MerchantStatus::Active->value,
                'approved_at' => now(),
                'approved_by_admin_id' => $admin->id,
                'created_by_admin_id' => $admin->id,
                'commission_rate_override' => '0.0500',
            ],
        );

        $this->seedOrders($customer1, $customer2, $seller, $merchant, $driverA);

        $this->command?->info('DemoSeeder complete — all accounts use password "password":');
        $this->command?->info('  admin   +218910001000   | staff   +218910001001 (office manager)');
        $this->command?->info('  drivers +218910001010 (car, online), +218910001011 (moto, online)');
        $this->command?->info('  users   +218910001020/21, seller +218910001022');
        $this->command?->info('  merchant +218910001030 (Acme Store, active, 5% commission)');
        $this->command?->info("  region '{$region->name}' / office '{$office->name}' (Tripoli polygon)");
    }

    /** @return array{0: Region, 1: OfficeLocation} */
    private function seedGeography(): array
    {
        $existing = ServiceArea::query()->where('name', 'Demo Service Area')->first();
        if ($existing !== null) {
            $region = Region::query()->where('service_area_id', $existing->id)->firstOrFail();

            return [$region, OfficeLocation::query()->where('region_id', $region->id)->firstOrFail()];
        }

        // Square ring around central Tripoli (lat 32.80–32.95, lng 13.10–13.30).
        // Point::makeGeodetic(lat, lng) — SRID 4326. (service_areas/regions are the
        // documented public_id exemption — no public_id column.)
        $ring = LineString::make([
            Point::makeGeodetic(32.80, 13.10),
            Point::makeGeodetic(32.80, 13.30),
            Point::makeGeodetic(32.95, 13.30),
            Point::makeGeodetic(32.95, 13.10),
            Point::makeGeodetic(32.80, 13.10),
        ], 4326);
        $boundary = Polygon::make([$ring], 4326);

        $serviceArea = ServiceArea::create([
            'name' => 'Demo Service Area',
            'boundary' => $boundary,
            'is_active' => true,
        ]);

        $region = Region::create([
            'service_area_id' => $serviceArea->id,
            'name' => 'Tripoli Central',
            'boundary' => $boundary,
            'is_active' => true,
            'base_fee' => '10.00',
        ]);

        $office = OfficeLocation::create([
            'public_id' => (string) Str::ulid(),
            'region_id' => $region->id,
            'name' => 'Tripoli Central Office',
            'address' => 'Algeria Square, Tripoli',
            'location' => Point::makeGeodetic(32.8872, 13.1913),
            'is_active' => true,
        ]);

        $region->forceFill(['office_id' => $office->id])->save();

        return [$region->fresh(), $office];
    }

    private function account(string $phone, string $first, string $last, string $role): User
    {
        $user = User::firstOrCreate(
            ['phone_number' => $phone],
            [
                'public_id' => (string) Str::ulid(),
                'first_name' => $first,
                'last_name' => $last,
                'email' => mb_strtolower($first.'.'.$last.'@demo.test'),
                'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
                'account_status' => AccountStatus::Active->value,
                'locale' => 'ar',
            ],
        );
        $user->syncRoles([$role]);

        return $user;
    }

    private function onlineDriver(string $phone, string $first, string $last, OfficeLocation $office, VehicleType $vehicle): User
    {
        $driver = $this->account($phone, $first, $last, 'driver');

        // driver_profiles / driver_accounts have no public_id (drivers use User.public_id).
        DriverProfile::firstOrCreate(
            ['user_id' => $driver->id],
            [
                'office_id' => $office->id,
                'status' => DriverStatus::Active->value,
                'vehicle_type' => $vehicle->value,
                'vehicle_plate' => 'DEMO-'.mb_substr($phone, -4),
                'activity_status' => DriverActivityStatus::Online->value,
                'current_location' => Point::makeGeodetic(32.8872, 13.1913),
                'last_location_updated_at' => now(),
            ],
        );

        DriverAccount::firstOrCreate(
            ['driver_id' => $driver->id],
            ['max_cash_liability' => '1000.00', 'cash_to_deposit' => '0.00', 'earnings_balance' => '0.00', 'debt_balance' => '0.00'],
        );

        return $driver;
    }

    private function seedOrders(User $customer1, User $customer2, User $seller, MerchantProfile $merchant, User $driver): void
    {
        if (Order::query()->where('sender_name', 'Demo: awaiting')->exists()) {
            return; // already seeded
        }

        // Two standard deliveries awaiting a driver (broadcast pool).
        $this->demoOrder([
            'sender_user_id' => $customer1->id, 'sender_name' => 'Demo: awaiting',
            'status' => OrderStatus::AwaitingDriver->value, 'awaiting_driver_at' => now(),
        ]);
        $this->demoOrder([
            'sender_user_id' => $customer2->id, 'sender_name' => 'Demo: awaiting',
            'status' => OrderStatus::AwaitingDriver->value, 'awaiting_driver_at' => now(),
        ]);

        // One assigned, en route to pickup.
        $this->demoOrder([
            'sender_user_id' => $customer1->id, 'sender_name' => 'Demo: assigned',
            'status' => OrderStatus::DriverEnRoutePickup->value, 'driver_id' => $driver->id, 'assigned_at' => now(),
        ]);

        // One delivered standard order.
        $this->demoOrder([
            'sender_user_id' => $customer2->id, 'sender_name' => 'Demo: delivered',
            'status' => OrderStatus::Delivered->value, 'driver_id' => $driver->id,
            'assigned_at' => now()->subHour(), 'delivered_at' => now(),
            'delivery_fee_status' => DeliveryFeeStatus::Paid->value,
        ]);

        // One P2P sale (seller → buyer), delivered, with item price.
        $this->demoOrder([
            'sender_user_id' => $seller->id, 'sender_name' => 'Demo: p2p sale',
            'order_type' => OrderType::P2pSale->value, 'status' => OrderStatus::Delivered->value,
            'item_price' => '120.00', 'commission_rate' => '0.0500', 'commission_amount' => '6.00',
            'delivery_fee_payer' => DeliveryFeePayer::Receiver->value, 'driver_id' => $driver->id,
            'delivered_at' => now(), 'delivery_fee_status' => DeliveryFeeStatus::Paid->value,
        ]);

        // One merchant delivery, awaiting a driver.
        $this->demoOrder([
            'sender_user_id' => $merchant->user_id, 'sender_name' => $merchant->business_name,
            'order_type' => OrderType::MerchantDelivery->value, 'merchant_profile_id' => $merchant->id,
            'status' => OrderStatus::AwaitingDriver->value, 'item_price' => '80.00',
            'commission_rate' => '0.0500', 'commission_amount' => '4.00',
            'delivery_fee_payer' => DeliveryFeePayer::Receiver->value, 'awaiting_driver_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $attrs */
    private function demoOrder(array $attrs): void
    {
        Order::factory()->create(array_merge([
            'public_id' => (string) Str::ulid(),
            'tracking_token' => (string) Str::ulid(),
            'search_radius_tier' => 1,
            'driver_assignment_attempts' => 0,
        ], $attrs));
    }
}
