<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'user' => (new UserResource($user))->resolve($request),
            'roles' => $user->getRoleNames()->values(),
            'must_change_password' => (bool) $user->must_change_password,
            'is_driver' => $user->isDriver(),
            'is_merchant' => $user->isMerchant(),
            'office_assignments' => $user->activeOfficeAssignments()
                ->with('office')
                ->get()
                ->map(fn ($assignment) => [
                    'id' => $assignment->office?->public_id,
                    'name' => $assignment->office?->name,
                ])
                ->values(),
            'counts' => [
                'pending_orders' => Order::query()->where('status', OrderStatus::AwaitingDriver->value)->count(),
                'unread_notifications' => $user->unreadNotifications()->count(),
            ],
        ]);
    }
}
