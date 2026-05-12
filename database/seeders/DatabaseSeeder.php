<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $seeders = [
            RolesSeeder::class,
            PlatformSettingsSeeder::class,
            AuthSettingsSeeder::class,
            OrderLifecyclePlatformSettingsSeeder::class,
        ];

        if (app()->environment(['local', 'development', 'testing'])) {
            $seeders[] = TestStaffSeeder::class;
        }

        $this->call($seeders);
    }
}
