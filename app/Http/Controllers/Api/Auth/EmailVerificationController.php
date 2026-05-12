<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Enums\AuthErrorCode;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EmailVerificationController extends Controller
{
    public function __construct(private readonly EmailVerificationService $service) {}

    /**
     * Public signed-URL endpoint. The `signed` middleware on the route has
     * already validated the URL signature + expiry by the time we arrive
     * here. We only have to confirm the hash still matches the user's
     * CURRENT email (defense against email-changed-after-link-issued).
     */
    public function verify(Request $request, int $id, string $hash): JsonResponse
    {
        $user = User::findOrFail($id);

        if (! $this->service->verify($user, $hash)) {
            return $this->errorResponse(
                AuthErrorCode::VerificationLinkInvalid,
                'Verification link is invalid or expired.',
            );
        }

        return response()->json(['message' => 'Email verified.']);
    }

    /**
     * Auth-required endpoint — sends a fresh verification link to the
     * authenticated user's currently-attached email. Rejects if email is
     * already verified or if no email is on file.
     */
    public function resend(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->email === null) {
            return $this->errorResponse(
                AuthErrorCode::ValidationFailed,
                'No email address on file. Add one via PATCH /api/me/profile first.',
            );
        }
        if ($user->email_verified_at !== null) {
            return $this->errorResponse(
                AuthErrorCode::AlreadyVerified,
                'Email is already verified.',
            );
        }

        $this->service->sendVerificationLink($user);

        return response()->json(['message' => 'Verification link sent.']);
    }

    private function errorResponse(AuthErrorCode $code, string $message): JsonResponse
    {
        return response()->json([
            'error' => $code->value,
            'message' => $message,
        ], $code->httpStatus());
    }
}
