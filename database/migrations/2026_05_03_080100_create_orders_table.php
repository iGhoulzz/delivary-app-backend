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
        // The orders table is the financial-and-operational heart of the
        // platform. Two principles dominate its design:
        //
        // 1. SNAPSHOT, NEVER RECALCULATE. Every rate and amount is captured
        //    at creation and never recomputed. This is non-negotiable per
        //    docs/CLAUDE.md.
        //
        // 2. STATUS TIMESTAMPS LIVE ON THE ROW. The full audit log is kept
        //    in `order_status_logs`, but we also denormalise key transition
        //    times here for fast filtering and display.
        Schema::create('orders', function (Blueprint $table) {
            // ─── Identity ────────────────────────────────────────────────
            $table->id();
            $table->ulid('public_id')->unique();
            $table->ulid('tracking_token')->unique(); // for guest /track URLs

            // ─── Type & status ───────────────────────────────────────────
            $table->string('order_type')->index();
            // values: standard_delivery, p2p_sale, merchant_delivery
            $table->string('status')->default('created')->index();
            // 16 values — see App\Enums\OrderStatus
            $table->timestamp('status_changed_at')->nullable()->index();

            // ─── Sender (always a registered user) ───────────────────────
            $table->foreignId('sender_user_id')->constrained('users')->restrictOnDelete();
            // Snapshot — protects against profile changes/deletions.
            $table->string('sender_phone');
            $table->string('sender_name');

            // ─── Pickup ──────────────────────────────────────────────────
            $table->text('pickup_address');
            $table->magellanPoint('pickup_location', 4326, 'GEOGRAPHY');
            $table->text('pickup_notes')->nullable();
            $table->string('pickup_code'); // 4-6 digit numeric, snapshot at creation
            $table->unsignedTinyInteger('pickup_code_attempts')->default(0);
            $table->string('picked_up_method')->nullable();
            // values: code, geofence_confirmation, admin_override

            // ─── Receiver (registered user OR guest — exactly one FK set) ─
            $table->string('receiver_type'); // values: registered_user, guest
            $table->foreignId('receiver_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('receiver_guest_id')->nullable()
                ->constrained('guest_recipients')->nullOnDelete();
            // Always-captured snapshot regardless of receiver_type.
            $table->string('receiver_phone');
            $table->string('receiver_name');
            $table->text('receiver_address');
            $table->magellanPoint('receiver_location', 4326, 'GEOGRAPHY');
            $table->text('receiver_notes')->nullable();
            $table->string('delivery_code'); // 4-6 digit numeric
            $table->unsignedTinyInteger('delivery_code_attempts')->default(0);
            $table->string('delivered_method')->nullable();
            // values: code, admin_override

            // ─── Merchant (only for order_type = merchant_delivery) ──────
            $table->foreignId('merchant_profile_id')->nullable()
                ->constrained('merchant_profiles')->nullOnDelete();

            // ─── Driver assignment ───────────────────────────────────────
            $table->foreignId('driver_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('driver_assignment_attempts')->default(0);
            $table->unsignedTinyInteger('search_radius_tier')->default(1);
            // 1 = base radius, 2 = +20% surcharge, 3 = +50% surcharge

            // ─── Item ────────────────────────────────────────────────────
            $table->text('item_description');
            $table->string('item_size')->index();
            // values: small, medium, large, xlarge
            $table->decimal('item_weight_kg', 6, 2)->nullable();
            $table->decimal('item_value', 12, 2)->nullable();
            // declared value for insurance/display only — not financial flow

            // ─── Financial snapshots (immutable after creation) ──────────
            // Item price (cash, sales orders only — always 0 for standard delivery)
            $table->decimal('item_price', 12, 2)->default(0);

            // Item commission
            $table->decimal('commission_rate', 5, 4)->default(0);
            $table->decimal('commission_amount', 12, 2)->default(0);

            // Delivery fee — base + surcharge from radius expansion
            $table->decimal('delivery_fee_base', 12, 2);
            $table->unsignedSmallInteger('delivery_fee_surcharge_percent')->default(0);
            $table->decimal('delivery_fee', 12, 2);
            // delivery_fee = delivery_fee_base * (1 + surcharge_percent/100)

            // Driver platform cut
            $table->decimal('driver_fee_cut_rate', 5, 4)->default(0);
            $table->decimal('driver_fee_cut_amount', 12, 2)->default(0);

            // Delivery fee payment metadata
            $table->string('delivery_fee_payer'); // values: sender, receiver
            $table->string('delivery_fee_payment_method')->default('cash');
            // values: cash, wallet  (wallet is architecture-ready, inactive at MVP)
            $table->string('delivery_fee_status')->default('unpaid')->index();
            // values: unpaid, paid, refunded
            $table->timestamp('delivery_fee_paid_at')->nullable();

            // ─── Architecture-ready, inactive in MVP ─────────────────────
            $table->decimal('tip_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->timestamp('scheduled_for')->nullable();

            // ─── Returns & storage ───────────────────────────────────────
            $table->foreignId('return_office_id')->nullable()
                ->constrained('office_locations')->nullOnDelete();
            $table->string('return_reason')->nullable();
            // values: receiver_refused, receiver_unreachable, address_invalid,
            //         item_damaged, driver_fault
            $table->string('return_fault')->nullable();
            // values: sender, receiver, driver, platform
            $table->timestamp('returned_to_office_at')->nullable();
            $table->timestamp('retrieved_by_seller_at')->nullable();
            $table->timestamp('abandoned_at')->nullable();
            $table->decimal('storage_fee_accrued', 12, 2)->default(0);

            // ─── Cancellation ────────────────────────────────────────────
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->decimal('cancellation_fee', 12, 2)->default(0);

            // ─── Status transition timestamps (denormalised for filtering) ─
            $table->timestamp('awaiting_driver_at')->nullable();
            $table->timestamp('no_driver_available_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('driver_en_route_pickup_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('driver_en_route_dropoff_at')->nullable();
            $table->timestamp('delivery_in_progress_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('delivery_failed_at')->nullable();
            $table->timestamp('returning_to_office_at')->nullable();
            $table->timestamp('at_office_at')->nullable();

            // ─── Standard ────────────────────────────────────────────────
            $table->timestamps();
            $table->softDeletes();

            // ─── Composite indexes for hot read paths ────────────────────
            $table->index(['driver_id', 'status']);          // driver's active order
            $table->index(['sender_user_id', 'status']);     // user's open orders
            $table->index(['receiver_user_id', 'status']);   // received open orders
            $table->index(['merchant_profile_id', 'status']);
            $table->index(['status', 'created_at']);          // admin queues
        });

        // GIST indexes for geography columns (radius matching, region containment).
        DB::statement('CREATE INDEX orders_pickup_location_idx ON orders USING GIST (pickup_location)');
        DB::statement('CREATE INDEX orders_receiver_location_idx ON orders USING GIST (receiver_location)');
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
