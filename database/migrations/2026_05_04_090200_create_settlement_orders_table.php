<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pivot: which orders' cash was included in a settlement, and how
        // much each order contributed. An order is settled at most once,
        // but we keep many-to-many for completeness (re-settlements, splits).
        Schema::create('settlement_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount_contributed', 12, 2);
            $table->timestamps();

            $table->unique(['settlement_id', 'order_id']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_orders');
    }
};
