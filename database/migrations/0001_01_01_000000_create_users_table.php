<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            // Internal primary key
            $table->id();

            // Public-facing ID (for URLs, APIs, exposure to other parties)
            $table->ulid('public_id')->unique();

            // Identity
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('phone_number')->unique(); // E.164 format: +218911234567
            $table->string('email')->nullable()->unique();

            // Authentication
            $table->string('password')->nullable(); // nullable for OTP-only users
            $table->rememberToken();

            // Verification
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();

            // Account state — replaces is_banned with flexible enum
            $table->string('account_status')->default('active')->index();
            // values: active, pending_verification, suspended,
            //         suspended_unpaid_fees, banned

            // Suspension/ban audit trail
            $table->timestamp('suspended_at')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->foreignId('suspended_by_admin_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // Engagement tracking
            $table->timestamp('last_active_at')->nullable();

            // User preferences
            $table->string('locale', 5)->default('ar');
            $table->boolean('push_notifications_enabled')->default(true);
            $table->boolean('sms_notifications_enabled')->default(true);
            $table->boolean('email_notifications_enabled')->default(true);

            // Push notification token (FCM/APNs)
            $table->string('fcm_token')->nullable();
            $table->timestamp('fcm_token_updated_at')->nullable();

            // Timestamps & soft delete
            $table->timestamps();
            $table->softDeletes();
        });

        // Laravel password resets (kept as-is)
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // Laravel sessions (kept as-is)
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
