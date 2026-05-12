<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guest receivers — phone-verified delivery recipients without an
        // account. Tracked by phone (E.164) so we can auto-merge their history
        // if they later sign up.
        Schema::create('guest_recipients', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('phone_number')->unique();

            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();

            $table->timestamp('first_received_at')->nullable();
            $table->timestamp('last_received_at')->nullable();
            $table->unsignedInteger('total_deliveries')->default(0);

            // Conversion: when this guest signs up with the same phone we
            // back-link the user and preserve the history.
            $table->foreignId('converted_to_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();

            $table->timestamps();

            $table->index('converted_to_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_recipients');
    }
};
