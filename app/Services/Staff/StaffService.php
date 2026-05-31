<?php

declare(strict_types=1);

namespace App\Services\Staff;

use App\Enums\AccountStatus;
use App\Enums\StaffErrorCode;
use App\Exceptions\Staff\StaffDomainException;
use App\Models\OfficeStaffAssignment;
use App\Models\User;
use App\Support\DTO\CreateStaffInput;
use App\Support\DTO\UpdateStaffInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class StaffService
{
    public function __construct(
        private readonly TempPasswordGenerator $passwords,
        private readonly OfficeAssignmentService $officeAssignments,
    ) {}

    /**
     * @return array{user: User, temporary_password: string}
     */
    public function create(CreateStaffInput $input, User $actor): array
    {
        return DB::transaction(function () use ($input): array {
            $tempPassword = $this->passwords->generate();

            $user = User::create([
                'phone_number' => $input->phoneNumber,
                'first_name' => $input->firstName,
                'last_name' => $input->lastName,
                'email' => $input->email,
                'password' => Hash::make($tempPassword),
                'must_change_password' => true,
                'account_status' => AccountStatus::Active->value,
                'phone_verified_at' => now(),
            ]);

            $user->assignRole($input->role);

            if ($input->role === 'office_staff') {
                $this->officeAssignments->attachMany($user, $input->officeAssignments);
            }

            return [
                'user' => $user->fresh(),
                'temporary_password' => $tempPassword,
            ];
        });
    }

    public function update(User $staff, UpdateStaffInput $input): User
    {
        $staff->fill(array_filter([
            'first_name' => $input->firstName,
            'last_name' => $input->lastName,
            'email' => $input->email,
        ], fn ($v) => $v !== null))->save();

        return $staff->fresh();
    }

    public function suspend(User $staff, User $actor): User
    {
        $this->assertNotSelf($staff, $actor);
        $this->assertNotLastAdmin($staff);

        return DB::transaction(function () use ($staff): User {
            $staff->forceFill(['account_status' => AccountStatus::Suspended->value])->save();
            $staff->tokens()->delete();

            return $staff->fresh();
        });
    }

    public function reinstate(User $staff, User $actor): User
    {
        $staff->forceFill(['account_status' => AccountStatus::Active->value])->save();

        return $staff->fresh();
    }

    public function deactivate(User $staff, User $actor): User
    {
        $this->assertNotSelf($staff, $actor);
        $this->assertNotLastAdmin($staff);

        return DB::transaction(function () use ($staff): User {
            $staff->forceFill(['account_status' => AccountStatus::Suspended->value])->save();
            $staff->tokens()->delete();
            OfficeStaffAssignment::query()
                ->where('user_id', $staff->id)
                ->whereNull('removed_at')
                ->update(['removed_at' => now()]);

            return $staff->fresh();
        });
    }

    /**
     * @return array{user: User, temporary_password: string}
     */
    public function resetTempPassword(User $staff, User $actor): array
    {
        $this->assertNotSelf($staff, $actor);

        return DB::transaction(function () use ($staff): array {
            $tempPassword = $this->passwords->generate();

            $staff->forceFill([
                'password' => Hash::make($tempPassword),
                'must_change_password' => true,
            ])->save();

            $staff->tokens()->delete();

            return [
                'user' => $staff->fresh(),
                'temporary_password' => $tempPassword,
            ];
        });
    }

    private function assertNotSelf(User $staff, User $actor): void
    {
        if ($staff->id === $actor->id) {
            throw new StaffDomainException(
                StaffErrorCode::CannotSelfModify,
                'Admins cannot perform this action on their own account.',
            );
        }
    }

    private function assertNotLastAdmin(User $staff): void
    {
        if (! $staff->hasRole('admin')) {
            return;
        }

        $activeAdmins = User::query()
            ->role('admin')
            ->where('account_status', AccountStatus::Active->value)
            ->where('id', '!=', $staff->id)
            ->count();

        if ($activeAdmins < 1) {
            throw new StaffDomainException(
                StaffErrorCode::LastAdminProtected,
                'Cannot suspend or deactivate the last active admin.',
            );
        }
    }
}
