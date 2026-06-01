<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\DriverDocumentType;
use App\Models\DriverDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DriverDocument */
final class DriverDocumentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // Convention link: file lives in Spatie media collection
        // 'driver_document_'.{document_type} on the User model.
        $user = $this->driver;
        $type = $this->document_type instanceof DriverDocumentType
            ? $this->document_type->value
            : (string) $this->document_type;
        $collection = 'driver_document_'.$type;
        $media = $user?->getFirstMedia($collection);

        $url = null;
        if ($media !== null) {
            try {
                $url = $media->getTemporaryUrl(now()->addMinutes(15));
            } catch (\Throwable) {
                $url = $media->getUrl();
            }
        }

        return [
            'document_type' => $type,
            'verified' => (bool) $this->verified,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toDateString(),
            'notes' => $this->notes,
            'file_url' => $url,
            'file_name' => $media?->file_name,
            'file_size' => $media?->size,
            'mime_type' => $media?->mime_type,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
