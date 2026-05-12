<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();

            $table->string('document_type');
            // values: national_id_front, national_id_back, drivers_license,
            //         vehicle_registration, selfie, vehicle_photo_front,
            //         vehicle_photo_back, insurance

            $table->boolean('verified')->default(false)->index();
            $table->foreignId('verified_by_admin_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();

            $table->date('expires_at')->nullable(); // licenses, insurance
            $table->text('notes')->nullable();

            $table->timestamps();

            // A driver has one row per document slot; the actual file lives
            // in the `media` table (Spatie Media Library) attached to the User.
            $table->unique(['driver_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_documents');
    }
};
