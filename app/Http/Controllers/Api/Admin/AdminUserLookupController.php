<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Moderation\UserLookupRequest;
use App\Http\Resources\Moderation\UserLookupResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final class AdminUserLookupController extends Controller
{
    public function __invoke(UserLookupRequest $request): UserLookupResource|JsonResponse
    {
        $user = User::query()
            ->with('roles')
            ->where('phone_number', $request->phone())
            ->first();

        if ($user === null) {
            return new JsonResponse(['data' => null]);
        }

        return new UserLookupResource($user);
    }
}
