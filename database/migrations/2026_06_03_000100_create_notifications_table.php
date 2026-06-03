<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Standard Laravel database-notifications table (SYSTEM_SPECIFICATION §13.6
     * In-App Notification Center). It was specced but never built — Laravel does
     * not ship this migration by default, and the §17.1 schema groups only
     * enumerated business tables. Required for the realtime `NotificationReceived`
     * broadcast path (App\Listeners\BroadcastDatabaseNotification) to persist and
     * re-hydrate notifications. Forward-only/additive.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
