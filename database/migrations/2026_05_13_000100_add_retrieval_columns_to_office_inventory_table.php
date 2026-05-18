<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('office_inventory', function (Blueprint $table): void {
            $table->decimal('cash_collected_at_retrieval', 12, 2)->default(0)
                ->after('retrieved_by_staff_id');
            $table->decimal('retrieval_fees_waived_amount', 12, 2)->default(0)
                ->after('cash_collected_at_retrieval');
        });
    }

    public function down(): void
    {
        Schema::table('office_inventory', function (Blueprint $table): void {
            $table->dropColumn(['cash_collected_at_retrieval', 'retrieval_fees_waived_amount']);
        });
    }
};
