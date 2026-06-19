<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexUsersRequest;
use App\Http\Resources\Admin\UserDetailResource;
use App\Http\Resources\Admin\UserDirectoryResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class UserDirectoryController extends Controller
{
    public function index(IndexUsersRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();

        $query = User::query()
            ->with(['roles', 'driverProfile', 'merchantProfile'])
            ->withCount([
                'sentOrders as sent_orders_count',
                'receivedOrders as received_orders_count',
            ])
            ->when(
                isset($validated['account_status']),
                fn ($q) => $q->where('account_status', $validated['account_status']),
            )
            ->when(
                isset($validated['role']),
                fn ($q) => $q->whereHas('roles', fn ($roles) => $roles->where('name', $validated['role'])),
            );

        if (isset($validated['search'])) {
            $search = trim((string) $validated['search']);
            $query->where(static fn ($q) => $q
                ->where('first_name', 'ilike', '%'.$search.'%')
                ->orWhere('last_name', 'ilike', '%'.$search.'%')
                ->orWhere('phone_number', 'like', '%'.$search.'%')
                ->orWhere('email', 'ilike', '%'.$search.'%')
                ->orWhere('public_id', 'ilike', '%'.$search.'%'));
        }

        return UserDirectoryResource::collection(
            $query->latest('id')->paginate((int) ($validated['per_page'] ?? 25)),
        );
    }

    public function show(User $user): UserDetailResource
    {
        return new UserDetailResource($user->loadMissing(['roles', 'driverProfile', 'merchantProfile']));
    }
}
