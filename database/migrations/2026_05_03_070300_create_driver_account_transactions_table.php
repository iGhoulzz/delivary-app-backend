<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only ledger for every driver account bucket movement.
        // Amount is signed: positive = credit to bucket, negative = debit.
        // Polymorphic `reference` ties a transaction back to its cause
        // (order, settlement, payout, strike, etc.).
        Schema::create('driver_account_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();

            $table->string('bucket');
            // values: cash_to_deposit, earnings_balance, debt_balance

            $table->decimal('amount', 12, 2); // signed

            $table->string('reason');
            // values: order_completed, settlement, payout, cancellation_fee,
            //         settlement_shortage, settlement_excess, debt_offset,
            //         debt_payment, strike_fee, manual_adjustment

            $table->nullableMorphs('reference');

            // Snapshot of the bucket value AFTER applying this row's amount,
            // for fast point-in-time reconstruction without summing history.
            $table->decimal('balance_after', 12, 2);

            $table->text('notes')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['driver_id', 'bucket']);
            $table->index(['driver_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_account_transactions');
    }
};
