<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pivot: which operational regions a driver is assigned to serve.
        // The matching algorithm prefers same-region drivers (spec 7.3),
        // but a driver may still be eligible outside their assigned regions
        // based on radius/availability rules.
        Schema::create('driver_region', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('region_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['driver_id', 'region_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_region');
    }
};
