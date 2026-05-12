<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ProfileController extends Controller
{
    public function __construct(private readonly EmailVerificationService $emailVerification) {}

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'user' => (new UserResource($user))->resolve($request),
        ]);
    }

    /**
     * Updates whichever fields are present in the payload. The only
     * non-trivial side effect is on `email`: changing it to a new value
     * resets `email_verified_at` and triggers a fresh verification link to
     * the new address.
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();
        $emailChanged = false;

        DB::transaction(function () use ($user, $data, &$emailChanged): void {
            // Email change: must be a DIFFERENT value to trigger verification.
            // Same-value PATCHes are no-ops (they don't re-verify a stable
            // address).
            if (array_key_exists('email', $data) && $data['email'] !== $user->email) {
                $user->email = $data['email'];
                $user->email_verified_at = null;
                $emailChanged = true;
            }
            foreach (['first_name', 'last_name', 'locale'] as $field) {
                if (array_key_exists($field, $data)) {
                    $user->{$field} = $data[$field];
                }
            }
            $user->save();
        });

        // Out-of-transaction so a Mail failure doesn't roll back the profile
        // update. The user can resend via /api/auth/email/verify-resend.
        if ($emailChanged && $user->email !== null) {
            $this->emailVerification->sendVerificationLink($user);
        }

        return response()->json([
            'user' => (new UserResource($user->fresh()))->resolve($request),
        ]);
    }
}
