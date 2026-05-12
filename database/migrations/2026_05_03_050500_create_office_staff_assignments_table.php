<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_staff_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')
                ->constrained('office_locations')->cascadeOnDelete();
            $table->boolean('is_manager')->default(false);
            $table->timestamp('assigned_at');
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'office_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_staff_assignments');
    }
};
