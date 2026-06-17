<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Enums\MerchantStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Merchant\StoreMerchantRequest;
use App\Http\Requests\Admin\Merchant\UpdateMerchantRequest;
use App\Http\Requests\Admin\Moderation\UserLookupRequest;
use App\Http\Resources\MerchantResource;
use App\Http\Resources\Moderation\UserLookupResource;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\Merchant\MerchantOnboardingService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class MerchantController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly MerchantOnboardingService $merchants) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', MerchantProfile::class);

        $query = MerchantProfile::query()->with('user.roles');

        $status = $request->query('status');
        if (is_string($status) && MerchantStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        return MerchantResource::collection(
            $query->latest('id')->paginate((int) $request->query('per_page', 20)),
        );
    }

    public function lookup(UserLookupRequest $request): UserLookupResource|JsonResponse
    {
        $this->authorize('viewAny', MerchantProfile::class);

        $user = User::query()
            ->with('roles')
            ->where('phone_number', $request->phone())
            ->first();

        if ($user === null) {
            return new JsonResponse(['data' => null]);
        }

        return new UserLookupResource($user);
    }

    public function store(StoreMerchantRequest $request): JsonResponse
    {
        $this->authorize('create', MerchantProfile::class);

        $merchant = $this->merchants->create($request->user(), $request->validated());

        return (new MerchantResource($merchant->load('user.roles')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(MerchantProfile $merchant): MerchantResource
    {
        $this->authorize('view', $merchant);

        return new MerchantResource($merchant->load('user.roles'));
    }

    public function update(UpdateMerchantRequest $request, MerchantProfile $merchant): MerchantResource
    {
        $this->authorize('update', $merchant);

        return new MerchantResource(
            $this->merchants->update($request->user(), $merchant, $request->validated())->load('user.roles'),
        );
    }

    public function suspend(Request $request, MerchantProfile $merchant): MerchantResource
    {
        $this->authorize('suspend', $merchant);

        return new MerchantResource(
            $this->merchants->suspend($request->user(), $merchant)->load('user.roles'),
        );
    }

    public function reactivate(Request $request, MerchantProfile $merchant): MerchantResource
    {
        $this->authorize('reactivate', $merchant);

        return new MerchantResource(
            $this->merchants->reactivate($request->user(), $merchant)->load('user.roles'),
        );
    }

    public function ban(Request $request, MerchantProfile $merchant): MerchantResource
    {
        $this->authorize('ban', $merchant);

        return new MerchantResource(
            $this->merchants->ban($request->user(), $merchant)->load('user.roles'),
        );
    }
}
