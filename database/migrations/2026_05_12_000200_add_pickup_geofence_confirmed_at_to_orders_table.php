<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Set by sender when they confirm "the driver is at my pickup" in their app.
            // Consumed by the driver's confirm-pickup with method=geofence within 5 minutes
            // (TTL hardcoded in CodeVerificationService; not a tunable knob).
            $table->timestamp('pickup_geofence_confirmed_at')->nullable()->after('pickup_code_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('pickup_geofence_confirmed_at');
        });
    }
};
