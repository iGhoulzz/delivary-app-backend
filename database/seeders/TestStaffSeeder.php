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
            $this->command?->warn('TestStaffSeeder: no active office found; skipping.');

            return;
        }

        // Office staff
        $staff = User::firstOrCreate(
            ['phone_number' => '+218910000001'],
            [
                'first_name' => 'Test',
                'last_name' => 'Staff',
                'password' => Hash::make('password123'),
                'phone_verified_at' => now(),
                'account_status' => AccountStatus::Active,
                'locale' => 'ar',
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
                'first_name' => 'Test',
                'last_name' => 'Admin',
                'password' => Hash::make('password123'),
                'phone_verified_at' => now(),
                'account_status' => AccountStatus::Active,
                'locale' => 'ar',
            ],
        );
        $admin->syncRoles(['admin']);

        $this->command?->info('TestStaffSeeder: staff=+218910000001 admin=+218910000002 (password=password123)');
    }
}
