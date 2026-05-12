<?php

declare(strict_types=1);

namespace App\Services\Driver;

use App\Enums\DriverDocumentType;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class DriverDocumentService
{
    public function upload(
        DriverProfile $profile,
        DriverDocumentType $type,
        UploadedFile $file,
        ?string $expiresAt,
        ?string $notes,
    ): DriverDocument {
        return DB::transaction(function () use ($profile, $type, $file, $expiresAt, $notes): DriverDocument {
            $user = $profile->user;
            $user->addMedia($file->getRealPath())
                ->usingFileName($file->getClientOriginalName())
                ->toMediaCollection('driver_document_'.$type->value);

            return DriverDocument::updateOrCreate(
                [
                    'driver_id' => $user->id,
                    'document_type' => $type->value,
                ],
                [
                    'verified' => false,
                    'verified_by_admin_id' => null,
                    'verified_at' => null,
                    'expires_at' => $expiresAt,
                    'notes' => $notes,
                ],
            );
        });
    }

    public function delete(DriverDocument $document): void
    {
        DB::transaction(function () use ($document): void {
            $user = $document->driver;
            $type = $document->document_type instanceof DriverDocumentType
                ? $document->document_type->value
                : (string) $document->document_type;
            $user->clearMediaCollection('driver_document_'.$type);
            $document->delete();
        });
    }
}
