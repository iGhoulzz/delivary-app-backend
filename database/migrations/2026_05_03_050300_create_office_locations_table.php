<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_locations', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('region_id')->nullable()
                ->constrained('regions')->nullOnDelete();
            $table->string('name');
            $table->text('address');
            $table->magellanPoint('location', 4326, 'GEOGRAPHY');
            $table->string('phone')->nullable();
            $table->json('operating_hours')->nullable();
            // example: {"mon":"09:00-18:00","tue":"09:00-18:00",...}
            $table->boolean('is_active')->default(true)->index();
            $table->integer('capacity')->nullable();
            $table->foreignId('manager_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement('CREATE INDEX office_locations_location_idx ON office_locations USING GIST (location)');
    }

    public function down(): void
    {
        Schema::dropIfExists('office_locations');
    }
};
