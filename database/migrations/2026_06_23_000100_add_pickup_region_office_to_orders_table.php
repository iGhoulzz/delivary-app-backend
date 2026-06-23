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
        Schema::table('orders', function (Blueprint $table): void {
            // Snapshot of the pickup region + its office, resolved ONCE at order
            // creation (PricingService). Finance by-office attribution reads these
            // columns instead of re-running ST_Contains at report time — this
            // prevents double-counting when active regions overlap and keeps
            // historical finance reports stable even if region boundaries change
            // later. Matches the existing snapshot approach for commission/fees
            // (Critical Rule 1). Nullable: existing rows are backfilled below;
            // pickups that resolve to no active region (or a region with no office)
            // stay null and surface as "unassigned".
            $table->foreignId('pickup_region_id')->nullable()->after('pickup_location')
                ->constrained('regions')->nullOnDelete();
            $table->foreignId('pickup_office_id')->nullable()->after('pickup_region_id')
                ->constrained('office_locations')->nullOnDelete();
            $table->index('pickup_office_id');
        });

        // Backfill existing orders by resolving each pickup against the active
        // region (inside an active service area) that currently contains it.
        // Overlapping regions resolve to one arbitrary match — the same
        // single-region outcome PricingService::resolveRegion() produces via
        // LIMIT 1. Pickups outside any active region stay null (= unassigned).
        DB::statement(<<<'SQL'
            UPDATE orders o
            SET pickup_region_id = r.id,
                pickup_office_id  = r.office_id
            FROM regions r
            JOIN service_areas sa ON sa.id = r.service_area_id
            WHERE r.is_active = true
              AND sa.is_active = true
              AND ST_Contains(r.boundary::geometry, o.pickup_location::geometry)
              AND o.pickup_region_id IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pickup_office_id');
            $table->dropConstrainedForeignId('pickup_region_id');
        });
    }
};
