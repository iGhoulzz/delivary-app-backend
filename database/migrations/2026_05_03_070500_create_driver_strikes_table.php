<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Driver strike ledger. Strikes can be issued automatically by the
        // system (e.g., accept-then-cancel) or manually by an admin. Each
        // strike may carry a fee that is also recorded in
        // driver_account_transactions (under reason `strike_fee`).
        //
        // NOTE: order_id has no FK constraint yet — the orders table is
        // built in Group 7. We add the FK in that group's migration.
        Schema::create('driver_strikes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();

            $table->unsignedBigInteger('order_id')->nullable()->index();
            // FK constraint added in the orders migration (Group 7).

            $table->string('reason');
            // values: accept_then_cancel, no_show_at_pickup, no_show_at_delivery,
            //         abandoned_order, repeated_lateness, customer_complaint,
            //         manual_admin

            $table->decimal('fee_amount', 12, 2)->default(0);

            $table->string('issued_by'); // values: system, admin
            $table->foreignId('issued_by_admin_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // Voiding (admin can invalidate a strike — e.g., real emergency)
            $table->boolean('is_voided')->default(false)->index();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_admin_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['driver_id', 'created_at']);
            $table->index(['driver_id', 'is_voided']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_strikes');
    }
};
