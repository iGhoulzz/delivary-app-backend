<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AccountStatus;
use App\Enums\DriverDocumentType;
use App\Enums\DriverStatus;
use App\Enums\DriverStrikeReason;
use App\Enums\MerchantStatus;
use App\Enums\ModerationReason;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\VehicleType;
use App\Http\Controllers\Controller;
use App\Models\OfficeLocation;
use App\Models\Region;
use Illuminate\Http\JsonResponse;

/**
 * Read-only reference catalogs for admin-dashboard dropdowns: offices, regions,
 * and the enum option lists. Frontend keeps its own AR/EN dictionary keyed by
 * value; the backend's job here is to be the authoritative value set (+ an
 * English label for convenience).
 */
final class ReferenceController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'offices' => OfficeLocation::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['public_id', 'name'])
                ->map(fn (OfficeLocation $office): array => [
                    'id' => $office->public_id,
                    'name' => $office->name,
                ])
                ->values(),
            'regions' => Region::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Region $region): array => [
                    'id' => $region->id, // reference data — numeric id is the deliberate exception
                    'name' => $region->name,
                ])
                ->values(),
            'enums' => [
                'driver_status' => self::options(DriverStatus::cases()),
                'account_status' => self::options(AccountStatus::cases()),
                'merchant_status' => self::options(MerchantStatus::cases()),
                'order_status' => self::options(OrderStatus::cases()),
                'order_type' => self::options(OrderType::cases()),
                'strike_reason' => self::options(DriverStrikeReason::cases()),
                'moderation_reason' => self::options(ModerationReason::cases()),
                'vehicle_type' => self::options(VehicleType::cases()),
                'document_type' => self::options(DriverDocumentType::cases()),
            ],
        ]);
    }

    /**
     * @param  array<int, \BackedEnum>  $cases
     * @return array<int, array{value: int|string, label: string}>
     */
    private static function options(array $cases): array
    {
        return array_map(static fn (\BackedEnum $case): array => [
            'value' => $case->value,
            'label' => method_exists($case, 'label') ? $case->label() : (string) $case->value,
        ], $cases);
    }
}
