<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // On-demand payout requests from sellers (spec section 4.10). Sellers
        // request a payout, admin reviews and either approves+pays or rejects.
        // Single method: cash pickup at office. Sellers' earnings sit in their
        // Bavix wallet until they request a cash payout; on payment, the wallet
        // is debited and the seller receives physical cash at the chosen
        // office. No bank-transfer or off-platform disbursement is supported.
        // The `payout_method` enum is retained for future expansion (e.g.
        // mobile money) but currently has only one valid value.
        // Minimum payout amount is in platform_settings.min_payout_amount.
        Schema::create('seller_payouts', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique(); // exposed on receipts

            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);

            $table->string('payout_method')->default('cash_at_office');
            // Currently the only allowed value is `cash_at_office`. Kept as a
            // discriminator column so future methods can be added without a
            // schema change.

            $table->foreignId('office_id')
                ->constrained('office_locations')->restrictOnDelete();
            // Required for every payout — this is the office where the seller
            // will physically collect the cash. restrictOnDelete protects
            // historical payout records from losing their pickup-office
            // reference.

            $table->string('status')->default('pending')->index();
            // pending, approved, paid, rejected, cancelled

            $table->timestamp('requested_at')->useCurrent();

            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by_admin_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by_admin_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by_admin_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_payouts');
    }
};
