<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Note: office_id FK is added in a later migration to break the
        // circular dependency between regions and office_locations
        // (office_locations.region_id ↔ regions.office_id, both nullable).
        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_area_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // e.g., "Tripoli Center"
            $table->magellanPolygon('boundary', 4326, 'GEOGRAPHY');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        DB::statement('CREATE INDEX regions_boundary_idx ON regions USING GIST (boundary)');
    }

    public function down(): void
    {
        Schema::dropIfExists('regions');
    }
};
