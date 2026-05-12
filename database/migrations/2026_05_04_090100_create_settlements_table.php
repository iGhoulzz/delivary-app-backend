<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cash settlement at the office (spec section 11). One row per
        // successful "driver hands cash, agent counts, balances zero out"
        // event. On disagreement NO row is created — that's intentional per
        // spec section 11.4: half-committed financial records are forbidden.
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique(); // exposed on receipts

            $table->foreignId('driver_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('office_id')->constrained('office_locations')->restrictOnDelete();
            $table->foreignId('processed_by_staff_id')->constrained('users')->restrictOnDelete();

            // The single physical cash movement (only one of these is non-zero
            // per settlement).
            $table->decimal('cash_received_from_driver', 12, 2)->default(0);
            $table->decimal('cash_paid_to_driver', 12, 2)->default(0);

            // What each bucket cleared by — snapshot at settlement time.
            $table->decimal('cash_to_deposit_cleared', 12, 2)->default(0);
            $table->decimal('earnings_balance_cleared', 12, 2)->default(0);
            $table->decimal('debt_balance_cleared', 12, 2)->default(0);

            // Deviations from expected — pushed to debt_balance / refunded.
            $table->decimal('shortage_amount', 12, 2)->default(0);
            $table->decimal('excess_amount', 12, 2)->default(0);

            $table->string('status'); // completed, disputed, cancelled
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['driver_id', 'created_at']);
            $table->index(['office_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlements');
    }
};
