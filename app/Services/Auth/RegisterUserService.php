<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\AccountStatus;
use App\Enums\OtpPurpose;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RegisterUserService
{
    public function __construct(private readonly OtpService $otp) {}

    /**
     * Create a new user and issue a registration OTP to their phone.
     *
     * The User model casts `password` as `hashed`, so passing the plaintext
     * via mass assignment is correct — Eloquent hashes on save. Manually
     * calling Hash::make() before this would double-hash and break login.
     *
     * @param  array{
     *     phone_number: string,
     *     first_name: string,
     *     last_name?: ?string,
     *     email?: ?string,
     *     password: string,
     *     locale?: ?string,
     * }  $data
     */
    public function register(array $data): User
    {
        $user = DB::transaction(function () use ($data): User {
            $user = User::create([
                'phone_number' => $data['phone_number'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'] ?? null,
                'email' => $data['email'] ?? null,
                'password' => $data['password'],          // hashed by model cast
                'locale' => $data['locale'] ?? 'ar',
                'account_status' => AccountStatus::Active,
            ]);
            $user->assignRole('user');

            return $user;
        });

        // Out-of-transaction so SMS failure doesn't roll back the user record.
        // The user can resend via /auth/otp/request later if SMS dropped.
        $this->otp->issue($user->phone_number, OtpPurpose::Registration);

        return $user;
    }
}
