<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only audit trail for admin account-moderation actions
     * (suspend/ban/reinstate). The staff-action audit table deferred from the
     * Staff CRUD milestone, scoped to moderation. See
     * docs/superpowers/specs/2026-06-03-account-moderation-design.md §6.
     */
    public function up(): void
    {
        Schema::create('account_moderation_actions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();   // target
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();  // admin
            $table->string('action');        // ModerationAction
            $table->string('reason_code');   // ModerationReason
            $table->text('detail');
            $table->string('from_status');   // AccountStatus
            $table->string('to_status');     // AccountStatus
            $table->timestamp('created_at')->nullable();   // append-only; no updated_at
            $table->index(['user_id', 'created_at']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_moderation_actions');
    }
};
