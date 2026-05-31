<?php

declare(strict_types=1);

use App\Enums\DriverDocumentType;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exposes documents and approvedBy relations', function (): void {
    $user = User::factory()->create();
    $profile = DriverProfile::factory()->create(['user_id' => $user->id]);
    DriverDocument::create([
        'driver_id' => $user->id,
        'document_type' => DriverDocumentType::NationalIdFront,
    ]);

    expect($profile->documents()->count())->toBe(1);
    expect($profile->documents->first())->toBeInstanceOf(DriverDocument::class);
    expect($profile->approvedBy())->not->toBeNull();
});
