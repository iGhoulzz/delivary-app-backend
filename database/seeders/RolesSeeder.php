<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        App::make(PermissionRegistrar::class)->forgetCachedPermissions();

        $roles = [
            'user',          // default for all signups (sender / buyer / receiver)
            'driver',        // approved drivers
            'merchant',      // shop operators
            'office_staff',  // process settlements & returns at offices
            'admin',         // full platform control
        ];

        foreach ($roles as $name) {
            Role::findOrCreate($name, 'web');
        }
    }
}
