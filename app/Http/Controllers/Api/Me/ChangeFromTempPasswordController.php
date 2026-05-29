<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Me;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\ChangeFromTempPasswordRequest;
use App\Http\Resources\UserResource;
use App\Services\Staff\TempPasswordChangeService;
use Illuminate\Http\JsonResponse;

final class ChangeFromTempPasswordController extends Controller
{
    public function __construct(private readonly TempPasswordChangeService $service) {}

    public function __invoke(ChangeFromTempPasswordRequest $request): JsonResponse
    {
        $result = $this->service->change(
            $request->user(),
            $request->string('current_password')->toString(),
            $request->string('new_password')->toString(),
        );

        return response()->json([
            'user' => (new UserResource($result['user']))->resolve(),
            'token' => $result['token'],
        ]);
    }
}
