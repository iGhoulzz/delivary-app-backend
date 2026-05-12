<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('regions', function (Blueprint $table): void {
            // Per-region flat fee. Default 0; admin seeds real values via Tinker
            // post-deploy. The order's delivery_fee_base snapshots this value
            // at quote-time so historical orders are unaffected by later edits.
            $table->decimal('base_fee', 12, 2)->default(0)->after('boundary');
        });
    }

    public function down(): void
    {
        Schema::table('regions', function (Blueprint $table): void {
            $table->dropColumn('base_fee');
        });
    }
};
