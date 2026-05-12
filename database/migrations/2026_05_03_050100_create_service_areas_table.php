<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_areas', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g., "Tripoli Service Area"
            $table->magellanPolygon('boundary', 4326, 'GEOGRAPHY');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        DB::statement('CREATE INDEX service_areas_boundary_idx ON service_areas USING GIST (boundary)');
    }

    public function down(): void
    {
        Schema::dropIfExists('service_areas');
    }
};
