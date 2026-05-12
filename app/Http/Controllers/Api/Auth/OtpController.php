<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Enums\AuthErrorCode;
use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RequestOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Auth\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class OtpController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

    /**
     * Resend (or first-send when registration row was created via a different
     * pathway). Issuing the registration OTP is the primary side-effect of
     * RegisterUserService::register(), so this endpoint is mostly a "I lost
     * the SMS, send me another" handle.
     */
    public function request(RequestOtpRequest $request): JsonResponse
    {
        $phone = (string) $request->input('phone_number');
        $purpose = $request->purpose();
        $user = User::where('phone_number', $phone)->first();

        if ($purpose === OtpPurpose::Registration) {
            // Registration OTP only makes sense for an existing pre-verified
            // record. Refusing here is intentionally explicit (not anti-
            // enumeration) because the user must have come from /register.
            if ($user === null) {
                return $this->errorResponse(
                    AuthErrorCode::OtpInvalid,
                    'No pending registration for this phone.'
                );
            }
            if ($user->phone_verified_at !== null) {
                return $this->errorResponse(
                    AuthErrorCode::AlreadyVerified,
                    'Phone is already verified.'
                );
            }

            $this->otp->issue($phone, $purpose);

            return response()->json(['message' => 'OTP sent.']);
        }

        // password_reset — anti-enumeration: identical 200 either way, only
        // actually send SMS when the user exists.
        if ($user !== null) {
            $this->otp->issue($phone, $purpose);
        }

        return response()->json(['message' => 'OTP sent.']);
    }

    public function verify(VerifyOtpRequest $request): JsonResponse
    {
        $phone = (string) $request->input('phone_number');
        $code = (string) $request->input('code');
        $purpose = $request->purpose();

        if (! $this->otp->verify($phone, $code, $purpose)) {
            return $this->errorResponse(
                AuthErrorCode::OtpInvalid,
                'OTP is invalid or expired.'
            );
        }

        // OTP correctness implies a real user record exists for this phone.
        // (For password_reset on unknown phones, the cache entry would never
        // have been populated, so verify() above already returned false.)
        $user = User::where('phone_number', $phone)->firstOrFail();

        if ($purpose === OtpPurpose::Registration) {
            $user->phone_verified_at = now();
            $user->save();

            return response()->json(['message' => 'Phone verified. You may now log in.']);
        }

        // password_reset success — mint a single-use reset_token in the cache.
        // The token is opaque to the client; on use it's consumed by
        // POST /api/auth/password/reset/otp.
        $token = Str::random(64);
        $ttl = (int) PlatformSetting::get('password_reset_token_ttl_seconds', 600);
        Cache::put("password_reset_token:{$token}", $user->id, $ttl);

        return response()->json(['reset_token' => $token]);
    }

    private function errorResponse(AuthErrorCode $code, string $message): JsonResponse
    {
        return response()->json([
            'error' => $code->value,
            'message' => $message,
        ], $code->httpStatus());
    }
}
