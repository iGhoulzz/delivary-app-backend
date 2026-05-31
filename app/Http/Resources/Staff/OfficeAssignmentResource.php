<?php

declare(strict_types=1);

namespace App\Http\Resources\Staff;

use App\Models\OfficeStaffAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OfficeStaffAssignment
 */
final class OfficeAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var OfficeStaffAssignment $assignment */
        $assignment = $this->resource;

        return [
            'id' => $assignment->public_id,
            'office' => [
                'id' => $assignment->office?->public_id,
                'name' => $assignment->office?->name,
            ],
            'is_manager' => (bool) $assignment->is_manager,
            'assigned_at' => $assignment->assigned_at?->toIso8601String(),
            'removed_at' => $assignment->removed_at?->toIso8601String(),
        ];
    }
}
