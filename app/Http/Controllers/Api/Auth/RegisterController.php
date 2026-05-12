<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\RegisterUserService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class RegisterController extends Controller
{
    public function __construct(private readonly RegisterUserService $service) {}

    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $user = $this->service->register($request->validated());

        return response()->json([
            'user' => (new UserResource($user))->resolve($request),
            'message' => 'OTP sent to your phone. Verify to activate your account.',
        ], Response::HTTP_CREATED);
    }
}
