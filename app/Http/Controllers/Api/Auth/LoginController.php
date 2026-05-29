<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Enums\AuthErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\LoginService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;

final class LoginController extends Controller
{
    public function __construct(private readonly LoginService $service) {}

    public function __invoke(LoginRequest $request): JsonResponse
    {
        $phone = (string) $request->input('phone_number');
        $password = (string) $request->input('password');

        $result = $this->service->attempt($phone, $password);

        if (isset($result['error'])) {
            /** @var AuthErrorCode $err */
            $err = $result['error'];

            return $this->errorResponse($err, match ($err) {
                AuthErrorCode::InvalidCredentials => 'Phone number or password is incorrect.',
                AuthErrorCode::PhoneNotVerified => 'Verify your phone number before logging in.',
                AuthErrorCode::AccountNotLoginable => 'Your account is not permitted to log in.',
                default => 'Authentication failed.',
            });
        }

        // Success: clear per-phone counter so this user can keep logging in.
        // Per-IP counter persists — protects against credential-stuffing
        // sweeps that hop accounts on a single IP.
        //
        // Laravel's ThrottleRequests stores named-limiter counters under
        // `md5($limiterName . $rawKey)` rather than the raw key, so we must
        // mirror that formula here for the clear to land on the right cell.
        RateLimiter::clear(md5('login'.'login_phone:'.$phone));

        return response()->json([
            'token' => $result['token'],
            'user' => (new UserResource($result['user']))->resolve($request),
        ]);
    }

    private function errorResponse(AuthErrorCode $code, string $message): JsonResponse
    {
        return response()->json([
            'error' => $code->value,
            'message' => $message,
        ], $code->httpStatus());
    }
}
