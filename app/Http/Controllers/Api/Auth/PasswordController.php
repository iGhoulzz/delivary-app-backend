<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Enums\AuthErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordViaEmailRequest;
use App\Http\Requests\Auth\ResetPasswordViaOtpRequest;
use App\Services\Auth\PasswordResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final class PasswordController extends Controller
{
    public function __construct(private readonly PasswordResetService $service) {}

    /**
     * Initiate a password reset flow.
     *
     * Anti-enumeration: same generic 200 response whether or not the account
     * exists. The service layer no-ops silently when the identifier doesn't
     * match anyone.
     *
     * For `channel=otp`, the user then calls /otp/verify with
     * purpose=password_reset to receive a single-use reset_token.
     *
     * For `channel=email`, the user receives a signed-link email; the
     * frontend extracts the token and calls /password/reset/email.
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $identifier = (string) $request->input('identifier');
        $channel = (string) $request->input('channel');

        if ($channel === 'otp') {
            $this->service->sendOtp($identifier);
        } else {
            $this->service->sendEmailLink($identifier);
        }

        return response()->json([
            'message' => 'If the account exists, instructions were sent.',
        ]);
    }

    public function resetViaOtp(ResetPasswordViaOtpRequest $request): Response|JsonResponse
    {
        $result = $this->service->resetViaOtp(
            (string) $request->input('reset_token'),
            (string) $request->input('new_password'),
        );
        if ($result instanceof AuthErrorCode) {
            return $this->errorResponse($result, 'Reset token is invalid or expired.');
        }

        return response()->noContent();
    }

    public function resetViaEmail(ResetPasswordViaEmailRequest $request): Response|JsonResponse
    {
        $result = $this->service->resetViaEmail(
            (string) $request->input('signed_token'),
            (string) $request->input('new_password'),
        );
        if ($result instanceof AuthErrorCode) {
            return $this->errorResponse($result, match ($result) {
                AuthErrorCode::EmailNotVerified => 'Email is not verified.',
                default => 'Reset token is invalid or expired.',
            });
        }

        return response()->noContent();
    }

    private function errorResponse(AuthErrorCode $code, string $message): JsonResponse
    {
        return response()->json([
            'error' => $code->value,
            'message' => $message,
        ], $code->httpStatus());
    }
}
