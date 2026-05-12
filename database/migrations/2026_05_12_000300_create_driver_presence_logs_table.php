<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_presence_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->string('event'); // values: went_online, went_offline, auto_offline
            $table->string('reason')->nullable(); // gps_lost | idle | manual | admin_unassign | ...
            $table->magellanPoint('location', 4326, 'GEOGRAPHY')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['driver_id', 'created_at']);
            $table->index(['event', 'created_at']);
        });

        DB::statement('CREATE INDEX driver_presence_logs_location_idx ON driver_presence_logs USING GIST (location)');
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_presence_logs');
    }
};
