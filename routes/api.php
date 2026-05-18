<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Admin\DriverController as AdminDriverController;
use App\Http\Controllers\Api\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\Auth\EmailVerificationController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\MeController;
use App\Http\Controllers\Api\Auth\OtpController;
use App\Http\Controllers\Api\Auth\PasswordController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\Driver\AccountController as DriverAccountController;
use App\Http\Controllers\Api\Driver\Order\ArrivedDropoffController as DriverOrderArrivedDropoffController;
use App\Http\Controllers\Api\Driver\Order\BroadcastController as DriverOrderBroadcastController;
use App\Http\Controllers\Api\Driver\Order\ClaimController as DriverOrderClaimController;
use App\Http\Controllers\Api\Driver\Order\ConfirmDeliveryController as DriverOrderConfirmDeliveryController;
use App\Http\Controllers\Api\Driver\Order\ConfirmPickupController as DriverOrderConfirmPickupController;
use App\Http\Controllers\Api\Driver\Order\MarkDeliveryFailedController as DriverOrderMarkDeliveryFailedController;
use App\Http\Controllers\Api\Driver\PresenceController as DriverPresenceController;
use App\Http\Controllers\Api\Driver\ProfileController as DriverViewProfileController;
use App\Http\Controllers\Api\Driver\RegionController as DriverRegionController;
use App\Http\Controllers\Api\Driver\Settlement\ListSettlementsController;
use App\Http\Controllers\Api\Driver\Settlement\ShowSettlementController;
use App\Http\Controllers\Api\Me\Driver\DriverProfileController as MeDriverProfileController;
use App\Http\Controllers\Api\Me\Driver\PreregistrationController;
use App\Http\Controllers\Api\Me\Order\CancelController;
use App\Http\Controllers\Api\Me\Order\GeofenceConfirmController;
use App\Http\Controllers\Api\Me\Order\RetryController;
use App\Http\Controllers\Api\Me\Settlement\ListSellerPayoutsController;
use App\Http\Controllers\Api\Me\Settlement\ShowEarningsController;
use App\Http\Controllers\Api\Me\Settlement\ShowSellerPayoutController;
use App\Http\Controllers\Api\Office\DriverDocumentController;
use App\Http\Controllers\Api\Office\DriverOnboardingController;
use App\Http\Controllers\Api\Office\Order\OrderController as OfficeOrderController;
use App\Http\Controllers\Api\Office\Order\ReceiveReturnController as OfficeOrderReceiveReturnController;
use App\Http\Controllers\Api\Office\Order\RetrieveOrderController as OfficeOrderRetrieveOrderController;
use App\Http\Controllers\Api\Order\OrderController;
use App\Http\Controllers\Api\Order\QuoteController;
use App\Http\Controllers\Api\Profile\ProfileController;
use App\Http\Controllers\Api\Tracking\GuestTrackingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ─── Auth ────────────────────────────────────────────────────────────────
Route::prefix('auth')->group(function (): void {
    // Public — no token required
    Route::post('register', RegisterController::class);

    Route::post('login', LoginController::class)
        ->middleware('throttle:login');

    Route::post('otp/request', [OtpController::class, 'request'])
        ->middleware('throttle:otp_request');
    Route::post('otp/verify', [OtpController::class, 'verify'])
        ->middleware('throttle:otp_verify');

    // Public signed-URL — `signed` middleware validates URL signature & expiry.
    Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware('signed')
        ->name('auth.email.verify');

    // Password reset — public. `forgot` is throttled like an OTP request
    // (3/15min/phone) to prevent SMS-bomb / email-bomb abuse.
    // `reset/otp` consumes the single-use token from /otp/verify.
    // `reset/email` consumes the signed token from the verification email,
    // throttled per-IP so a stolen token still can't be brute-forced fast.
    Route::post('password/forgot', [PasswordController::class, 'forgot'])
        ->middleware('throttle:forgot_password');
    Route::post('password/reset/otp', [PasswordController::class, 'resetViaOtp']);
    Route::post('password/reset/email', [PasswordController::class, 'resetViaEmail'])
        ->middleware('throttle:password_reset_email');

    // Auth-required (Sanctum bearer)
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('me', MeController::class);
        Route::post('email/verify-resend', [EmailVerificationController::class, 'resend']);
        Route::post('logout', LogoutController::class);

    });
});

// ─── /me — profile (auth-required) ───────────────────────────────────────
Route::middleware('auth:sanctum')->prefix('me')->group(function (): void {
    Route::get('profile', [ProfileController::class, 'show']);
    Route::patch('profile', [ProfileController::class, 'update']);

    Route::prefix('orders')->group(function (): void {
        Route::get('/', [OrderController::class, 'index'])
            ->middleware('throttle:me_orders_read');
        Route::get('{order:public_id}', [OrderController::class, 'show'])
            ->middleware('throttle:me_orders_read');
        Route::post('{order:public_id}/retry', RetryController::class)
            ->middleware('throttle:me_action');
        Route::post('{order:public_id}/cancel', CancelController::class)
            ->middleware('throttle:me_action');
        Route::post('{order:public_id}/confirm-pickup-geofence', GeofenceConfirmController::class)
            ->middleware('throttle:me_action');
    });

    Route::get('earnings', ShowEarningsController::class)
        ->name('me.earnings.show')
        ->middleware('throttle:seller_earnings_read');
    Route::get('seller-payouts', ListSellerPayoutsController::class)
        ->name('me.seller-payouts.index');
    Route::get('seller-payouts/{sellerPayout:public_id}', ShowSellerPayoutController::class)
        ->name('me.seller-payouts.show');

    // Driver self-service: existing-user pre-registration as a driver
    Route::prefix('driver')->group(function (): void {
        Route::get('/', [MeDriverProfileController::class, 'show']);
        Route::post('preregister', PreregistrationController::class);
    });
});

// ─── /orders — order lifecycle (auth-required) ───────────────────────────
Route::middleware('auth:sanctum')->prefix('orders')->group(function (): void {
    Route::post('quote', QuoteController::class)
        ->middleware('throttle:orders_quote');
    Route::post('/', [OrderController::class, 'store'])
        ->middleware('throttle:orders_create');
});

// ─── /office/drivers — office-staff driver onboarding ────────────────────
Route::get('track/{trackingToken}', GuestTrackingController::class)
    ->middleware('throttle:guest_tracking');

Route::middleware(['auth:sanctum', 'role:office_staff'])->prefix('office/drivers')->group(function (): void {
    Route::get('/', [DriverOnboardingController::class, 'index']);
    Route::post('lookup', [DriverOnboardingController::class, 'lookup']);
    Route::post('onboard', [DriverOnboardingController::class, 'onboard']);
    Route::post('{driverProfile}/verify-phone', [DriverOnboardingController::class, 'verifyPhone']);
    Route::post('{driverProfile}/submit', [DriverOnboardingController::class, 'submit']);
    Route::post('{driverProfile}/documents', [DriverDocumentController::class, 'store']);
    Route::delete('{driverProfile}/documents/{driverDocument}', [DriverDocumentController::class, 'destroy']);
});

Route::middleware(['auth:sanctum', 'role:office_staff'])->prefix('office/orders')->group(function (): void {
    Route::get('/', [OfficeOrderController::class, 'index'])
        ->middleware('throttle:office_orders_read');
    Route::get('{order:public_id}', [OfficeOrderController::class, 'show'])
        ->middleware('throttle:office_orders_read');
    Route::post('{order:public_id}/receive-return', OfficeOrderReceiveReturnController::class)
        ->middleware('throttle:office_action');
    Route::post('{order:public_id}/retrieve', OfficeOrderRetrieveOrderController::class)
        ->middleware('throttle:office_action');
});

// ─── /office — settlement & seller payouts ──────────────────────────────
Route::middleware(['auth:sanctum', 'role:office_staff'])->prefix('office')->group(function (): void {
    Route::get('drivers/{driverPublicId}/settlement-preview', \App\Http\Controllers\Api\Office\Settlement\PreviewSettlementController::class)
        ->name('office.settlements.preview');

    Route::post('settlements', \App\Http\Controllers\Api\Office\Settlement\ProcessSettlementController::class)
        ->middleware('throttle:office_settlement')
        ->name('office.settlements.process');
    Route::get('settlements', \App\Http\Controllers\Api\Office\Settlement\ListSettlementsController::class)
        ->name('office.settlements.index');

    Route::get('seller-payouts/lookup', \App\Http\Controllers\Api\Office\Settlement\LookupSellerPayoutController::class)
        ->name('office.seller-payouts.lookup');
    Route::post('seller-payouts', \App\Http\Controllers\Api\Office\Settlement\ProcessSellerPayoutController::class)
        ->middleware('throttle:office_payout')
        ->name('office.seller-payouts.process');
    Route::get('seller-payouts', \App\Http\Controllers\Api\Office\Settlement\ListSellerPayoutsController::class)
        ->name('office.seller-payouts.index');
});

// ─── /admin/drivers — admin driver lifecycle management ─────────────────
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/drivers')->group(function (): void {
    Route::get('/', [AdminDriverController::class, 'index']);
    Route::get('{driverProfile}', [AdminDriverController::class, 'show']);
    Route::post('{driverProfile}/approve', [AdminDriverController::class, 'approve']);
    Route::post('{driverProfile}/reject', [AdminDriverController::class, 'reject']);
    Route::post('{driverProfile}/suspend', [AdminDriverController::class, 'suspend']);
    Route::post('{driverProfile}/reinstate', [AdminDriverController::class, 'reinstate']);
});

// ─── /driver — driver self-service ──────────────────────────────────────
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/orders')->group(function (): void {
    Route::get('/', [AdminOrderController::class, 'index']);
    Route::get('{order:public_id}', [AdminOrderController::class, 'show']);
    Route::post('{order:public_id}/assign', [AdminOrderController::class, 'assign']);
    Route::post('{order:public_id}/unassign', [AdminOrderController::class, 'unassign']);
    Route::post('{order:public_id}/cancel', [AdminOrderController::class, 'cancel']);
    Route::post('{order:public_id}/mark-delivery-failed', [AdminOrderController::class, 'markDeliveryFailed']);
    Route::post('{order:public_id}/redirect-return', [AdminOrderController::class, 'redirectReturn']);
    Route::post('{order:public_id}/waive-retrieval-fees', [AdminOrderController::class, 'waiveRetrievalFees']);
});

// ─── /admin — settlement & seller payouts ───────────────────────────────
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function (): void {
    Route::get('settlements', \App\Http\Controllers\Api\Admin\Settlement\ListSettlementsController::class)
        ->name('admin.settlements.index');
    Route::get('settlements/{settlement:public_id}', \App\Http\Controllers\Api\Admin\Settlement\ShowSettlementController::class)
        ->name('admin.settlements.show');
    Route::post('settlements/{settlement:public_id}/reverse', \App\Http\Controllers\Api\Admin\Settlement\ReverseSettlementController::class)
        ->name('admin.settlements.reverse');

    Route::get('seller-payouts', \App\Http\Controllers\Api\Admin\Settlement\ListSellerPayoutsController::class)
        ->name('admin.seller-payouts.index');
});

Route::middleware(['auth:sanctum', 'role:driver'])->prefix('driver')->group(function (): void {
    Route::get('profile', DriverViewProfileController::class);
    Route::get('account', DriverAccountController::class);
    Route::get('settlements', ListSettlementsController::class)
        ->name('driver.settlements.index');
    Route::get('settlements/{settlement:public_id}', ShowSettlementController::class)
        ->name('driver.settlements.show');
    Route::get('regions', [DriverRegionController::class, 'index']);
    Route::patch('regions', [DriverRegionController::class, 'update']);
    Route::post('go-online', [DriverPresenceController::class, 'goOnline'])
        ->middleware('throttle:driver_action');
    Route::post('go-offline', [DriverPresenceController::class, 'goOffline'])
        ->middleware('throttle:driver_action');
    Route::post('location', [DriverPresenceController::class, 'location'])
        ->middleware('throttle:driver_location');

    Route::prefix('orders')->group(function (): void {
        Route::get('broadcast', [DriverOrderBroadcastController::class, 'index'])
            ->middleware('throttle:driver_action');
        Route::get('current', [DriverOrderBroadcastController::class, 'current'])
            ->middleware('throttle:driver_action');
        Route::post('{order:public_id}/claim', DriverOrderClaimController::class)
            ->middleware('throttle:driver_action');
        Route::post('{order:public_id}/confirm-pickup', DriverOrderConfirmPickupController::class)
            ->middleware('throttle:driver_action');
        Route::post('{order:public_id}/arrived-dropoff', DriverOrderArrivedDropoffController::class)
            ->middleware('throttle:driver_action');
        Route::post('{order:public_id}/confirm-delivery', DriverOrderConfirmDeliveryController::class)
            ->middleware('throttle:driver_action');
        Route::post('{order:public_id}/mark-delivery-failed', DriverOrderMarkDeliveryFailedController::class)
            ->middleware('throttle:driver_action');
    });
});

// ─── Default Sanctum scaffolding (kept until cleanup pass) ───────────────
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
