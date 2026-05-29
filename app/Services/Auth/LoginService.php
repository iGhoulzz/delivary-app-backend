<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\AuthErrorCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

final class LoginService
{
    /**
     * Attempt to authenticate a user.
     *
     * Returns a result array:
     *   - On success: ['user' => User, 'token' => string]
     *   - On failure: ['error' => AuthErrorCode]
     *
     * Anti-enumeration: "no such phone" and "wrong password" both return
     * `InvalidCredentials`. `PhoneNotVerified` is only emitted when the
     * password actually matches — at that point the caller has already
     * proven account ownership, so the verification leak is acceptable.
     *
     * @return array{user: User, token: string} | array{error: AuthErrorCode}
     */
    public function attempt(string $phone, string $password): array
    {
        $user = User::where('phone_number', $phone)->first();

        if ($user === null || ! Hash::check($password, (string) $user->password)) {
            return ['error' => AuthErrorCode::InvalidCredentials];
        }

        if ($user->phone_verified_at === null) {
            return ['error' => AuthErrorCode::PhoneNotVerified];
        }

        if (! $user->account_status->canLogin()) {
            return ['error' => AuthErrorCode::AccountNotLoginable];
        }

        $token = $user->createToken('auth')->plainTextToken;

        return ['user' => $user, 'token' => $token];
    }
}
