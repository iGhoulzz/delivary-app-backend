<?php

declare(strict_types=1);

use App\Support\OrderNumber\OrderNumberBackfiller;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('order_number', 20)->nullable();
        });

        OrderNumberBackfiller::run(); // fill existing rows (the UNIQUE index below is the backstop)

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('order_number', 20)->nullable(false)->change();
            $table->unique('order_number');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique(['order_number']);
            $table->dropColumn('order_number');
        });
    }
};
