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
        // Wallet top-up requests (Group 9 — architecture-ready, inactive at
        // MVP). When a user wants to add funds to their Bavix wallet via a
        // gateway (Plutu) the lifecycle is captured here:
        //
        //   pending  → request created, not yet sent to gateway
        //   processing → handed off, awaiting gateway/webhook resolution
        //   completed  → wallet credited; wallet_transaction_id is set
        //   failed / cancelled / refunded → terminal, wallet untouched
        //                                  (or post-fact reversed in refund case)
        //
        // No business rule allows mutating a completed request — corrections
        // are made by issuing a new compensating request. Soft deletes apply
        // for audit retention, never to "undo" a record.
        Schema::create('topup_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            // restrictOnDelete: a user with completed top-ups must not be
            // hard-deletable — financial records survive the user record.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // Optional: when a user initiates a top-up using a saved card,
            // this links to the payment_methods row. nullOnDelete because the
            // saved card may be revoked later but the historical request
            // record must remain intact.
            $table->foreignId('payment_method_id')->nullable()
                ->constrained('payment_methods')->nullOnDelete();

            $table->decimal('amount', 12, 2);

            $table->string('status')->default('pending')->index();
            // values: pending, processing, completed, failed, cancelled, refunded

            // Gateway routing & correlation
            $table->string('gateway_provider'); // values: plutu (PaymentMethodProvider)
            $table->string('gateway_transaction_id')->nullable();
            // The gateway's id for this transaction. Hot column for webhook
            // lookups — uniquely indexed (NULLs allowed before gateway responds).
            $table->string('gateway_reference')->nullable();
            // Display-friendly reference shown to user / on receipt.

            // Post-completion link to the actual wallet credit. Bavix's
            // `transactions` table is package-owned, so we keep a soft pointer
            // (indexed, no FK) rather than coupling our migration to its
            // schema lifecycle. Reconciliation joins on this column.
            $table->unsignedBigInteger('wallet_transaction_id')->nullable()->index();

            $table->json('gateway_response')->nullable();
            // Full provider response captured for audit/debugging only.
            $table->text('failure_reason')->nullable();

            // Status-transition timestamps (denormalised for fast filtering).
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('refunded_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Composite indexes for the hot read paths
            $table->index(['user_id', 'status']);
            $table->index(['status', 'requested_at']);

            // Webhook lookup must be fast and gateway txn ids are unique per
            // provider — partial unique index excludes NULLs (pre-gateway).
        });

        // The "unique gateway_transaction_id when present" guarantee is
        // expressed as a Postgres partial unique index because we want NULLs
        // (rows still in `pending` before the gateway responds) to coexist.
        DB::statement(
            'CREATE UNIQUE INDEX topup_requests_gateway_txn_uniq '
            .'ON topup_requests (gateway_provider, gateway_transaction_id) '
            .'WHERE gateway_transaction_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('topup_requests');
    }
};
