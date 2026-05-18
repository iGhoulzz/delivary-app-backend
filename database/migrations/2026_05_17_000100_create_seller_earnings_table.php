<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1:1 with sale orders; created at delivery success, never at order creation.
        Schema::create('seller_earnings', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('seller_user_id')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('status')->index();
            $table->timestamp('cleared_at')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('paid_out_at')->nullable();
            $table->foreignId('paid_by_staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('seller_payout_id')->nullable()->constrained('seller_payouts')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['seller_user_id', 'status'], 'seller_earnings_seller_status_idx');
            $table->index(['status', 'cleared_at'], 'seller_earnings_status_cleared_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_earnings');
    }
};
