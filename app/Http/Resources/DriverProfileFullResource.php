<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use App\Models\DriverProfile;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DriverProfile */
final class DriverProfileFullResource extends JsonResource
{
    /**
     * Relations read by toArray(). Callers must loadMissing() these before
     * resolving so nested public ids are never emitted as null.
     *
     * @var array<int, string>
     */
    public const RELATIONS = ['user', 'user.driverRegions', 'office', 'approvedBy', 'documents.driver.media'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->user?->public_id,
            'status' => $this->status->value,
            'activity_status' => $this->activity_status->value,
            'office' => $this->relationLoaded('office') && $this->office !== null
                ? ['id' => $this->office->public_id, 'name' => $this->office->name]
                : null,
            'user' => $this->relationLoaded('user') && $this->user !== null ? [
                'id' => $this->user->public_id,
                'first_name' => $this->user->first_name,
                'last_name' => $this->user->last_name,
                'phone_number' => $this->user->phone_number,
                'phone_verified' => $this->user->phone_verified_at !== null,
                'email' => $this->user->email,
                'email_verified' => $this->user->email_verified_at !== null,
                'account_status' => $this->user->account_status->value,
            ] : null,
            'vehicle' => [
                'type' => $this->vehicle_type->value,
                'plate' => $this->vehicle_plate,
                'color' => $this->vehicle_color,
                'model' => $this->vehicle_model,
            ],
            'documents' => DriverDocumentResource::collection($this->whenLoaded('documents')),
            'audit' => [
                'created_at' => $this->created_at?->toIso8601String(),
                'approved_at' => $this->approved_at?->toIso8601String(),
                'approved_by' => $this->relationLoaded('approvedBy') && $this->approvedBy !== null
                    ? ['id' => $this->approvedBy->public_id, 'name' => $this->approvedBy->fullName()]
                    : null,
                'rejected_at' => $this->rejected_at?->toIso8601String(),
            ],
            'lifetime_deliveries' => $this->lifetime_deliveries,
            'rating_average' => $this->rating_average,
            'notes' => $this->notes,
            'last_active_at' => $this->last_active_at?->toIso8601String(),
            'deliveries_today' => $this->deliveriesToday(),
            'orders_as_customer_count' => $this->ordersAsCustomerCount(),
            'roles' => $this->user?->getRoleNames() ?? [],
            'regions' => $this->relationLoaded('user') && $this->user !== null
                ? $this->user->driverRegions->map(fn ($region): array => ['id' => $region->id, 'name' => $region->name])->values()
                : [],
            'notification_prefs' => [
                'push' => (bool) $this->user?->push_notifications_enabled,
                'sms' => (bool) $this->user?->sms_notifications_enabled,
                'email' => (bool) $this->user?->email_notifications_enabled,
            ],
        ];
    }

    private function deliveriesToday(): int
    {
        return Order::query()
            ->where('driver_id', $this->user_id)
            ->where('status', OrderStatus::Delivered->value)
            ->whereDate('delivered_at', today())
            ->count();
    }

    private function ordersAsCustomerCount(): int
    {
        $user = $this->user;

        if ($user === null) {
            return 0;
        }

        return $user->sentOrders()->count() + $user->receivedOrders()->count();
    }
}
