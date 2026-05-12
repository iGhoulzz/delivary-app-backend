<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Minimal merchant model — we are a delivery platform, not a marketplace
        // builder. We only track who created the order, where to pick up, and
        // the financial relationship. Merchant business complexity (catalog,
        // inventory, multiple locations) is not our concern.
        Schema::create('merchant_profiles', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('business_name');
            $table->string('business_phone')->nullable(); // falls back to user phone

            // Invite-only onboarding
            $table->string('status')->default('pending')->index();
            // values: pending, active, suspended, banned
            $table->foreignId('created_by_admin_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by_admin_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // Financial relationship — null = use platform default
            $table->decimal('commission_rate_override', 5, 4)->nullable();
            $table->decimal('driver_fee_cut_override', 5, 4)->nullable();

            // Optional default pickup — provided per-order at creation if absent
            $table->text('default_pickup_address')->nullable();
            $table->magellanPoint('default_pickup_location', 4326, 'GEOGRAPHY')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement('CREATE INDEX merchant_profiles_default_pickup_location_idx ON merchant_profiles USING GIST (default_pickup_location)');
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_profiles');
    }
};
