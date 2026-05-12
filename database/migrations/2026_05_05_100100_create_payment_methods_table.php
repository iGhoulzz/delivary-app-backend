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
        // Saved tokenised payment instruments (Group 9 — architecture-ready,
        // inactive at MVP). The platform never stores a raw PAN, CVV or full
        // expiry; only an opaque gateway-issued token plus display-safe
        // metadata (brand, last four, card-holder name, expiry month/year for
        // expiry-warning UI). The gateway_token column is `text` because the
        // model casts it to `encrypted` — Laravel's encrypter produces opaque
        // base64 blobs of unbounded length, hence text rather than string.
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique(); // exposed in URLs/APIs

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('provider'); // values: plutu (PaymentMethodProvider)
            $table->string('type');     // values: card  (PaymentMethodType)

            $table->text('gateway_token'); // encrypted at rest via model cast

            // Display-safe card metadata. All optional so non-card future
            // method types (bank account, mobile money) can leave them null.
            $table->string('card_brand')->nullable();        // visa, mastercard, amex, ...
            $table->char('card_last_four', 4)->nullable();
            $table->string('card_holder_name')->nullable();
            $table->unsignedTinyInteger('expiry_month')->nullable();   // 1-12
            $table->unsignedSmallInteger('expiry_year')->nullable();   // four-digit year

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            // is_active drops to false if the gateway tells us the token has
            // been revoked, expired, or chargeback-blocked. Soft-disable
            // rather than delete preserves audit trail for past topups.

            $table->json('gateway_metadata')->nullable();
            // Opaque provider-specific payload (BIN ranges, 3DS flags, etc.)

            $table->timestamps();
            $table->softDeletes();

            // Composite indexes for the two hot read paths:
            //   - "list this user's active methods"
            //   - "find this user's default method"
            $table->index(['user_id', 'is_active']);
            $table->index(['user_id', 'is_default']);
        });

        // Enforce "at most one default per user" at the database level via a
        // partial unique index. This guards against race conditions in
        // app-level "demote previous default" logic. Soft-deleted rows are
        // excluded so a user can recreate a default after deletion.
        DB::statement(
            'CREATE UNIQUE INDEX payment_methods_user_default_uniq '
            .'ON payment_methods (user_id) '
            .'WHERE is_default = true AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
