<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Office where the driver was registered (face-to-face onboarding)
            $table->foreignId('office_id')->nullable()
                ->constrained('office_locations')->nullOnDelete();

            // Account state (layer 1)
            $table->string('status')->default('pre_registered')->index();
            // values: pre_registered, pending_approval, approved, active,
            //         suspended, rejected, banned
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by_admin_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // Vehicle
            $table->string('vehicle_type'); // car, motorcycle
            $table->string('vehicle_plate');
            $table->string('vehicle_color')->nullable();
            $table->string('vehicle_model')->nullable();

            // Activity (layer 2 — runtime)
            $table->string('activity_status')->default('offline')->index();
            // values: offline, online, on_order
            $table->magellanPoint('current_location', 4326, 'GEOGRAPHY')->nullable();
            $table->timestamp('last_location_updated_at')->nullable();
            $table->timestamp('last_active_at')->nullable();

            // Performance
            $table->integer('lifetime_deliveries')->default(0);
            $table->decimal('rating_average', 3, 2)->default(5.00);

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement('CREATE INDEX driver_profiles_current_location_idx ON driver_profiles USING GIST (current_location)');
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_profiles');
    }
};
