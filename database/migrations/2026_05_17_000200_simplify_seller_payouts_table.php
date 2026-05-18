<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_payouts', function (Blueprint $table): void {
            $table->dropForeign(['approved_by_admin_id']);
            $table->dropColumn(['approved_at', 'approved_by_admin_id']);
            $table->dropForeign(['rejected_by_admin_id']);
            $table->dropColumn(['rejected_at', 'rejected_by_admin_id', 'rejection_reason']);
            $table->dropColumn('requested_at');
            $table->renameColumn('paid_by_admin_id', 'paid_by_staff_id');
        });

        Schema::table('seller_payouts', function (Blueprint $table): void {
            $table->timestamp('paid_at')->useCurrent()->change();
        });
    }

    public function down(): void
    {
        Schema::table('seller_payouts', function (Blueprint $table): void {
            $table->renameColumn('paid_by_staff_id', 'paid_by_admin_id');
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('paid_at')->nullable()->change();
        });
    }
};
