<?php

declare(strict_types=1);

namespace App\Services\Staff;

use App\Models\OfficeStaffAssignment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Slice B phase 1 uses RuntimeException with stable error-code messages.
 * After Slice A merges, these become StaffDomainException + StaffErrorCode.
 */
final class OfficeAssignmentService
{
    public function attach(User $staff, int $officeId, bool $isManager): OfficeStaffAssignment
    {
        if (! $staff->hasRole('office_staff')) {
            throw new RuntimeException('ROLE_MISMATCH_FOR_OFFICE_ASSIGN');
        }

        return DB::transaction(function () use ($staff, $officeId, $isManager): OfficeStaffAssignment {
            $this->assertNoActiveAssignment($staff, $officeId);

            return OfficeStaffAssignment::create([
                'user_id' => $staff->id,
                'office_id' => $officeId,
                'is_manager' => $isManager,
                'assigned_at' => now(),
                'removed_at' => null,
            ]);
        });
    }

    public function detach(User $staff, OfficeStaffAssignment $assignment): void
    {
        DB::transaction(function () use ($staff, $assignment): void {
            $assignment = OfficeStaffAssignment::query()
                ->whereKey($assignment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($assignment->user_id !== $staff->id || $assignment->removed_at !== null) {
                return;
            }

            $activeAssignments = OfficeStaffAssignment::query()
                ->where('user_id', $staff->id)
                ->whereNull('removed_at')
                ->lockForUpdate()
                ->get();

            if ($activeAssignments->count() <= 1) {
                throw new RuntimeException('OFFICE_ASSIGNMENT_LAST_REQUIRED');
            }

            $assignment->forceFill(['removed_at' => now()])->save();
        });
    }

    /**
     * @param  array<int, array{office_id: int, is_manager: bool}>  $assignments
     * @return Collection<int, OfficeStaffAssignment>
     */
    public function attachMany(User $staff, array $assignments): Collection
    {
        $this->assertNoDuplicateOfficeIds($assignments);

        foreach ($assignments as $assignment) {
            $this->assertNoActiveAssignment($staff, (int) $assignment['office_id']);
        }

        return collect($assignments)->map(function (array $assignment) use ($staff): OfficeStaffAssignment {
            return OfficeStaffAssignment::create([
                'user_id' => $staff->id,
                'office_id' => (int) $assignment['office_id'],
                'is_manager' => (bool) $assignment['is_manager'],
                'assigned_at' => now(),
                'removed_at' => null,
            ]);
        });
    }

    private function assertNoActiveAssignment(User $staff, int $officeId): void
    {
        $existing = OfficeStaffAssignment::query()
            ->where('user_id', $staff->id)
            ->where('office_id', $officeId)
            ->whereNull('removed_at')
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            throw new RuntimeException('OFFICE_ASSIGNMENT_DUPLICATE');
        }
    }

    /**
     * @param  array<int, array{office_id: int, is_manager: bool}>  $assignments
     */
    private function assertNoDuplicateOfficeIds(array $assignments): void
    {
        $officeIds = array_map(static fn (array $assignment): int => (int) $assignment['office_id'], $assignments);

        if (count($officeIds) !== count(array_unique($officeIds))) {
            throw new RuntimeException('OFFICE_ASSIGNMENT_DUPLICATE');
        }
    }
}
