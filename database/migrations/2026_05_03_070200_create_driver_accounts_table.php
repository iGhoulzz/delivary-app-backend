<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Three-bucket driver finance model. Each bucket is always stored as
        // a non-negative decimal; debt is its own bucket rather than a
        // negative earnings balance (see CLAUDE.md anti-patterns).
        //
        // The default `max_cash_liability` matches the platform setting
        // `new_driver_max_liability`; services should hydrate this on driver
        // activation rather than rely on the default.
        Schema::create('driver_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->unique()
                ->constrained('users')->cascadeOnDelete();

            // Live buckets (always >= 0)
            $table->decimal('cash_to_deposit', 12, 2)->default(0);
            $table->decimal('earnings_balance', 12, 2)->default(0);
            $table->decimal('debt_balance', 12, 2)->default(0);

            // Limit
            $table->decimal('max_cash_liability', 12, 2)->default(100);

            // Lifetime stats (monotonic, never decremented)
            $table->decimal('lifetime_earnings', 12, 2)->default(0);
            $table->decimal('lifetime_cash_handled', 12, 2)->default(0);
            $table->decimal('lifetime_platform_fees_paid', 12, 2)->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_accounts');
    }
};
