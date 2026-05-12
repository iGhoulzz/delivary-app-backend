<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Physical-tracking row for an order whose item lives at an office
        // (failed delivery returned, awaiting seller pickup or abandonment).
        // The order's lifecycle dates (returned_to_office_at, retrieved_by_seller_at,
        // abandoned_at) live on the orders table; this table holds the
        // operational details that change over time at the office:
        // shelf location, accrued storage fees, who handed it back, etc.
        Schema::create('office_inventory', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('order_id')->unique()
                ->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')
                ->constrained('office_locations')->restrictOnDelete();

            $table->foreignId('received_by_staff_id')
                ->constrained('users')->restrictOnDelete();
            $table->timestamp('received_at')->useCurrent();
            $table->string('shelf_location')->nullable();

            // Storage fees: accrue daily after the grace period
            // (platform_settings.storage_fee_grace_days, default 5 days).
            $table->decimal('accrued_storage_fee', 12, 2)->default(0);
            $table->date('last_fee_accrued_on')->nullable();

            // Retrieval (spec section 6.5)
            $table->timestamp('retrieved_at')->nullable();
            $table->foreignId('retrieved_by_staff_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // Abandonment (spec section 6.4 — 30 days after receipt)
            $table->timestamp('abandoned_at')->nullable();
            $table->foreignId('abandoned_by_admin_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('disposal_notes')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['office_id', 'received_at']);
            $table->index(['office_id', 'retrieved_at']);
            $table->index(['office_id', 'abandoned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_inventory');
    }
};
