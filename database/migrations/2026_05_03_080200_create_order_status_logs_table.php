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
        // Append-only audit trail. Every status transition appends one row.
        // Powers dispute resolution. Never delete or modify rows.
        Schema::create('order_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();

            $table->string('from_status')->nullable();
            // null on the very first row (the create itself).
            $table->string('to_status');

            $table->string('actor_type');
            // values: user, driver, admin, system, office_staff
            $table->unsignedBigInteger('actor_id')->nullable();
            // Not a FK — actor may be a user, but `system` actors have no id.
            // Polymorphic on the read side via actor_type + actor_id.

            $table->text('reason')->nullable();
            $table->text('notes')->nullable();

            // Where the actor (typically the driver) was at the moment of
            // transition — useful for fraud detection and "did the driver
            // really pickup at the right place" disputes.
            $table->magellanPoint('actor_location', 4326, 'GEOGRAPHY')->nullable();

            $table->json('metadata')->nullable();

            // Append-only — only created_at, no updates allowed at the model.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'created_at']);
            $table->index(['actor_type', 'actor_id']);
        });

        DB::statement('CREATE INDEX order_status_logs_actor_location_idx ON order_status_logs USING GIST (actor_location)');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_logs');
    }
};
