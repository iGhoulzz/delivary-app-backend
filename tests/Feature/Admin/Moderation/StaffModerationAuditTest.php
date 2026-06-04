<?php

declare(strict_types=1);

use App\Enums\AccountStatus;
use App\Models\User;
use App\Services\Staff\StaffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('office_staff', 'web');
});

it('staff suspension writes an account_moderation_actions audit row', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $staff = User::factory()->create(['account_status' => AccountStatus::Active->value]);
    $staff->assignRole('office_staff');

    app(StaffService::class)->suspend($staff, $admin);

    expect($staff->fresh()->account_status)->toBe(AccountStatus::Suspended);
    $this->assertDatabaseHas('account_moderation_actions', [
        'user_id' => $staff->id,
        'actor_id' => $admin->id,
        'action' => 'suspend',
    ]);
});
