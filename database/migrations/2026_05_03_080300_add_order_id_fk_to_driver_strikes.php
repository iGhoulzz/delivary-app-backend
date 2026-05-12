<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the deferred FK from driver_strikes.order_id → orders.id.
     * The column was created back in Group 5 (driver_strikes migration)
     * without a constraint because orders did not exist yet.
     */
    public function up(): void
    {
        Schema::table('driver_strikes', function (Blueprint $table) {
            $table->foreign('order_id')
                ->references('id')->on('orders')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('driver_strikes', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
        });
    }
};
