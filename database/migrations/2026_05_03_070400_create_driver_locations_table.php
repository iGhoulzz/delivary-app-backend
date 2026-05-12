<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Driver location history (transient — pruned after 7 days per spec
        // section 9.9). For the live "where is each driver right now" lookup,
        // use `driver_profiles.current_location` (overwritten in place);
        // this table is for audit, fraud detection, and analytics.
        //
        // Smart filtering (in app code, not DB): only insert if the driver
        // moved 50m+ OR 60s+ since their last row.
        Schema::create('driver_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();

            $table->magellanPoint('location', 4326, 'GEOGRAPHY');

            // Optional GPS metadata (forward-compat for fraud/analytics)
            $table->decimal('heading', 5, 2)->nullable();        // degrees 0-360
            $table->decimal('speed_mps', 6, 2)->nullable();      // meters per second
            $table->decimal('accuracy_meters', 6, 2)->nullable();
            $table->unsignedTinyInteger('battery_percentage')->nullable();

            // When the device captured the GPS reading (may differ from when
            // we received it, e.g. queued offline reports).
            $table->timestamp('recorded_at')->index();

            $table->timestamp('created_at')->useCurrent();
            // No updated_at — these rows are immutable.
            // No softDeletes — transient by design.

            $table->index(['driver_id', 'recorded_at']);
        });

        DB::statement('CREATE INDEX driver_locations_location_idx ON driver_locations USING GIST (location)');
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_locations');
    }
};
