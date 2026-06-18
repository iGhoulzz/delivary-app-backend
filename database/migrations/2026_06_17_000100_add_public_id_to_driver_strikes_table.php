<?php

declare(strict_types=1);

use App\Models\DriverStrike;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Additive: strikes now get an admin URL (void), so they need a public_id
     * per Critical Rule 11. New nullable column → backfill existing rows →
     * add the unique index. Forward-only, no behaviour change.
     */
    public function up(): void
    {
        Schema::table('driver_strikes', function (Blueprint $table): void {
            $table->ulid('public_id')->nullable()->after('id');
        });

        DriverStrike::query()->whereNull('public_id')->get()->each(function (DriverStrike $strike): void {
            $strike->forceFill(['public_id' => (string) Str::ulid()])->saveQuietly();
        });

        Schema::table('driver_strikes', function (Blueprint $table): void {
            $table->unique('public_id');
        });
    }

    public function down(): void
    {
        Schema::table('driver_strikes', function (Blueprint $table): void {
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
