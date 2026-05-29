<?php

declare(strict_types=1);

namespace App\Services\Staff;

use App\Enums\StaffErrorCode;
use App\Exceptions\Staff\StaffDomainException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class TempPasswordChangeService
{
    /**
     * @return array{user: User, token: string}
     */
    public function change(User $user, string $currentPassword, string $newPassword): array
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw new StaffDomainException(
                StaffErrorCode::TempPasswordMismatch,
                'Current password is incorrect.',
            );
        }

        if ($currentPassword === $newPassword) {
            throw new StaffDomainException(
                StaffErrorCode::NewPasswordSameAsTemp,
                'New password must not be the same as the temporary password.',
            );
        }

        return DB::transaction(function () use ($user, $newPassword): array {
            $user->forceFill([
                'password' => Hash::make($newPassword),
                'must_change_password' => false,
            ])->save();

            $user->tokens()->delete();
            $token = $user->createToken('post-temp-change')->plainTextToken;

            return [
                'user' => $user->fresh(),
                'token' => $token,
            ];
        });
    }
}
