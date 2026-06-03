<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Real-time (Reverb) milestone — integration smoke
//
// Phase 3 deliverable of docs/superpowers/specs/2026-05-18-realtime-reverb-design.md.
//
// Rather than booting a real Reverb server + socket client (a manual, infra
// gate), this script swaps in an in-process *recording broadcaster* and drives
// a real order lifecycle end-to-end, asserting the exact sequence of broadcast
// events, their channels, and — crucially — that each event's broadcastWith()
// payload serialises correctly OFF the HTTP request (the whole point of the
// broadcast-safe Resources). This exercises the same dispatch → event →
// broadcaster path Reverb uses, minus the websocket transport.
//
// Run:
//   php artisan tinker --execute="require base_path('scripts/realtime-smoke.php');"
//
// Self-contained: creates its own world, captures broadcasts, and deletes every
// row it created in a finally block (id-snapshot deletes). No outer transaction
// is used because broadcast events are deferred until after-commit — a rollback
// harness would suppress them entirely.
// ─────────────────────────────────────────────────────────────────────────

// Queue must run inline so queued ShouldBroadcast jobs broadcast synchronously.
config(['queue.default' => 'sync']);

use App\Enums\AccountStatus;
use App\Enums\DriverActivityStatus;
use App\Enums\DriverStatus;
use App\Enums\ItemSize;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\SellerEarningStatus;
use App\Enums\VehicleType;
use App\Jobs\ClearSellerEarningsJob;
use App\Models\DriverAccount;
use App\Models\DriverProfile;
use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\Region;
use App\Models\SellerEarning;
use App\Models\User;
use App\Services\Driver\PresenceService;
use App\Services\Order\ClaimService;
use App\Services\Order\CodeVerificationService;
use App\Services\Order\CreationService;
use App\Services\Order\EscalationService;
use App\Services\Order\QuoteService;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException('FAIL '.$message);
    }

    echo "PASS {$message}\n";
};

// ── Recording broadcaster ────────────────────────────────────────────────
$recorder = new class extends Broadcaster
{
    /** @var array<int, array{event: string, channels: array<int, string>, payload: array<string, mixed>}> */
    public array $captured = [];

    public function auth($request): mixed
    {
        return true;
    }

    public function validAuthenticationResponse($request, $result): mixed
    {
        return $result;
    }

    /** @param array<int, mixed> $channels */
    public function broadcast(array $channels, $event, array $payload = []): void
    {
        $this->captured[] = [
            'event' => (string) $event,
            'channels' => array_map(static fn ($c): string => (string) $c, $channels),
            'payload' => $payload,
        ];
    }
};

Broadcast::extend('recording', static fn (): object => $recorder);
config([
    'broadcasting.default' => 'recording',
    'broadcasting.connections.recording' => ['driver' => 'recording'],
]);

// Capture helpers operating on the recorder buffer.
$reset = static function () use ($recorder): void {
    $recorder->captured = [];
};
$recordsFor = static fn (string $event): array => array_values(
    array_filter($recorder->captured, static fn (array $b): bool => $b['event'] === $event),
);
$channelsFor = static function (string $event) use ($recordsFor): array {
    $channels = [];
    foreach ($recordsFor($event) as $record) {
        $channels = array_merge($channels, $record['channels']);
    }

    return array_values(array_unique($channels));
};

$uniquePhone = static function (int $offset): string {
    $suffix = str_pad((string) random_int(100000 + $offset, 899999 + $offset), 6, '0', STR_PAD_LEFT);

    return '+21893'.$suffix;
};

$makeUser = static function (string $firstName, string $phone, ?string $role = null): User {
    $user = User::create([
        'first_name' => $firstName,
        'last_name' => 'RTSmoke',
        'phone_number' => $phone,
        'password' => Hash::make('password'),
        'account_status' => AccountStatus::Active->value,
        'phone_verified_at' => now(),
    ]);

    if ($role !== null) {
        $user->assignRole($role);
    }

    return $user;
};

// ── Snapshots for cleanup (delete everything created with id > snapshot) ───
$maxId = static fn (string $table, string $column = 'id'): int => (int) (DB::table($table)->max($column) ?? 0);
$snap = [
    'users' => $maxId('users'),
    'orders' => $maxId('orders'),
    'office_locations' => $maxId('office_locations'),
];
$region = Region::query()->firstOrFail();
$regionOriginal = [
    'base_fee' => $region->base_fee,
    'is_active' => $region->is_active,
];
$serviceAreaActive = (bool) DB::table('service_areas')->where('id', $region->service_area_id)->value('is_active');

// NOTE: deliberately NOT wrapped in an outer transaction. Broadcast events set
// $afterCommit = true, so they only fire once the enclosing transaction commits.
// A rollback harness (like orders-e2e.php) would suppress every broadcast and
// the recorder would stay empty. Instead we commit for real and clean up by
// id-snapshot deletes in the finally block.
try {
    Role::findOrCreate('user', 'web');
    Role::findOrCreate('driver', 'web');
    Role::findOrCreate('admin', 'web');

    PlatformSetting::set('codes.enforce_pickup', true);
    PlatformSetting::set('codes.enforce_delivery', true);
    PlatformSetting::set('payouts.clearance_hours', 48);

    $region->forceFill(['base_fee' => '10.00', 'is_active' => true])->save();
    DB::table('service_areas')->where('id', $region->service_area_id)->update(['is_active' => true]);

    $centroid = DB::selectOne(
        'SELECT ST_X(ST_Centroid(boundary::geometry)) AS lng, ST_Y(ST_Centroid(boundary::geometry)) AS lat FROM regions WHERE id = ?',
        [$region->id],
    );
    $pickup = ['lat' => (float) $centroid->lat, 'lng' => (float) $centroid->lng];
    $dropoff = ['lat' => (float) $centroid->lat + 0.001, 'lng' => (float) $centroid->lng + 0.001];

    $sender = $makeUser('Sender', $uniquePhone(1), 'user');
    $driver = $makeUser('Driver', $uniquePhone(2), 'driver');

    DriverProfile::create([
        'user_id' => $driver->id,
        'office_id' => null,
        'status' => DriverStatus::Active->value,
        'vehicle_type' => VehicleType::Car->value,
        'vehicle_plate' => 'RT-'.Str::upper(Str::random(4)),
        'activity_status' => DriverActivityStatus::Offline->value,
    ]);
    DriverAccount::create([
        'driver_id' => $driver->id,
        'cash_to_deposit' => '0.00',
        'earnings_balance' => '0.00',
        'debt_balance' => '0.00',
        'max_cash_liability' => '1000.00',
    ]);

    // A second online driver in the broadcast pool. They never claim, so they
    // are the audience for OrderBroadcastWithdrawn when the first driver claims
    // (the claimer flips to OnOrder and drops out of the eligible set).
    $bystander = $makeUser('Bystander', $uniquePhone(3), 'driver');
    DriverProfile::create([
        'user_id' => $bystander->id,
        'office_id' => null,
        'status' => DriverStatus::Active->value,
        'vehicle_type' => VehicleType::Car->value,
        'vehicle_plate' => 'RT-'.Str::upper(Str::random(4)),
        'activity_status' => DriverActivityStatus::Offline->value,
    ]);
    DriverAccount::create([
        'driver_id' => $bystander->id,
        'cash_to_deposit' => '0.00',
        'earnings_balance' => '0.00',
        'debt_balance' => '0.00',
        'max_cash_liability' => '1000.00',
    ]);

    $createOrder = static function () use ($sender, $pickup, $dropoff, $uniquePhone): Order {
        $quote = app(QuoteService::class)->quote(
            OrderType::StandardDelivery,
            $pickup['lat'],
            $pickup['lng'],
            $dropoff['lat'],
            $dropoff['lng'],
            ItemSize::Small,
            '0.00',
            'sender',
        );

        return app(CreationService::class)->create($sender, [
            'quote_token' => $quote['quote_token'],
            'order_type' => OrderType::StandardDelivery->value,
            'pickup_location' => $pickup,
            'pickup_address' => 'RT pickup',
            'receiver_location' => $dropoff,
            'receiver_address' => 'RT dropoff',
            'receiver_phone' => $uniquePhone(random_int(100, 9999)),
            'receiver_name' => 'RT Receiver',
            'item_size' => ItemSize::Small->value,
            'item_description' => 'RT smoke parcel',
            'delivery_fee_payer' => 'sender',
        ]);
    };

    // ── Scenario 1: order broadcast to eligible drivers on tier escalation ──
    echo "Scenario 1: OrderBroadcastToDriver fans out to eligible drivers\n";
    $order = $createOrder();
    app(PresenceService::class)->goOnline($driver, $pickup);
    app(PresenceService::class)->goOnline($bystander, ['lat' => $pickup['lat'] + 0.0002, 'lng' => $pickup['lng'] + 0.0002]);
    $order->forceFill([
        'awaiting_driver_at' => now()->subMinutes(4),
        'status_changed_at' => now()->subMinutes(4),
    ])->save();

    $reset();
    app(EscalationService::class)->process($order->refresh());
    $assert(count($recordsFor('order.broadcast_to_driver')) >= 1, 'tier escalation broadcasts order to driver');
    $assert(in_array('private-driver.'.$driver->public_id, $channelsFor('order.broadcast_to_driver'), true),
        'broadcast_to_driver targets the eligible driver private channel');

    // ── Scenario 2: claim drives status + withdrawal + driver-assigned ──────
    echo "Scenario 2: claim broadcasts status, withdrawal, and driver-assigned\n";
    $reset();
    $order = app(ClaimService::class)->claim($driver, $order->refresh());
    $publicId = $order->public_id;
    $token = $order->tracking_token;

    $assert($order->status === OrderStatus::DriverEnRoutePickup, 'claim moves order to en_route_pickup');
    $assert(in_array('private-driver.'.$bystander->public_id, $channelsFor('order.broadcast_withdrawn'), true),
        'claim withdraws the order from the other pool drivers');
    $assert(in_array('private-order.'.$publicId, $channelsFor('order.status_changed'), true),
        'status_changed broadcasts on the private order channel (public_id)');
    $assert(in_array('track.'.$token, $channelsFor('order.status_changed_public'), true),
        'status_changed_public broadcasts on the public tracking channel');
    $assignedChannels = $channelsFor('order.driver_assigned');
    $assert(in_array('private-order.'.$publicId, $assignedChannels, true)
        && in_array('track.'.$token, $assignedChannels, true),
        'driver_assigned broadcasts on both the order and tracking channels');

    // Payload safety: order channel must not leak sender/receiver-only fields.
    $statusPayload = $recordsFor('order.status_changed')[0]['payload']['order'] ?? [];
    $assert(($statusPayload['id'] ?? null) === $publicId, 'status_changed payload exposes public_id as id');
    $assert(! array_key_exists('phone', $statusPayload['receiver'] ?? []), 'status_changed hides receiver phone');
    $assert(! array_key_exists('name', $statusPayload['receiver'] ?? []), 'status_changed hides receiver name');
    $assert(! array_key_exists('commission_amount', $statusPayload['pricing'] ?? []), 'status_changed hides commission');

    // Payload safety: driver-assigned must not leak internal driver fields.
    $driverPayload = $recordsFor('order.driver_assigned')[0]['payload']['driver'] ?? [];
    foreach (['id', 'user_id', 'office_id', 'vehicle_plate'] as $forbidden) {
        $assert(! array_key_exists($forbidden, $driverPayload), "driver_assigned hides {$forbidden}");
    }

    // ── Scenario 3: live location streams to order + tracking channels ──────
    echo "Scenario 3: OrderDriverLocationUpdated streams to parties + public\n";
    $reset();
    app(PresenceService::class)->updateLocation($driver, [
        'lat' => $pickup['lat'] + 0.0005,
        'lng' => $pickup['lng'] + 0.0005,
        'heading' => 90.0,
        'accuracy_meters' => 5.0,
    ]);
    $locationChannels = $channelsFor('order.driver_location_updated');
    $assert(in_array('private-order.'.$publicId, $locationChannels, true)
        && in_array('track.'.$token, $locationChannels, true),
        'location update streams to both order and tracking channels');
    $locationPayload = $recordsFor('order.driver_location_updated')[0]['payload'] ?? [];
    $assert(($locationPayload['order_public_id'] ?? null) === $publicId, 'location payload carries order public_id');
    $assert(array_key_exists('lat', $locationPayload) && array_key_exists('lng', $locationPayload), 'location payload carries coordinates');

    // ── Scenario 4: delivery credits driver → DriverAccountUpdated ──────────
    echo "Scenario 4: delivery completion broadcasts DriverAccountUpdated\n";
    $order = app(CodeVerificationService::class)->confirmPickup($driver, $order->refresh(), 'code', $order->pickup_code);
    app(PresenceService::class)->updateLocation($driver, $dropoff);
    $order = app(CodeVerificationService::class)->arrivedDropoff($driver, $order->refresh());
    $reset();
    $order = app(CodeVerificationService::class)->confirmDelivery($driver, $order->refresh(), $order->delivery_code);
    $assert($order->status === OrderStatus::Delivered, 'order delivered');
    $assert(in_array('private-driver.'.$driver->public_id, $channelsFor('driver.account_updated'), true),
        'earnings credit broadcasts driver.account_updated on the driver channel');

    // ── Scenario 5: database notification fans out to the user channel ──────
    // The NotificationReceived event carries a DatabaseNotification and uses
    // SerializesModels, so the broadcast queue round-trips it by id — which only
    // works against a persisted row in a real `notifications` table. This project
    // has no notifications-table migration yet (database notifications are
    // dormant), so we skip the end-to-end check here. The listener → event
    // mapping is covered by tests/Unit/Events/RealtimePhase2EventsTest.
    echo "Scenario 5: database notification broadcasts notification.received\n";
    if (! Schema::hasTable('notifications')) {
        echo "SKIP no `notifications` table in this project — database notifications are not yet wired (out of realtime scope)\n";
    } else {
        $reset();
        $sender->notify(new class extends Notification
        {
            /** @return array<int, string> */
            public function via($notifiable): array
            {
                return ['database'];
            }

            /** @return array<string, mixed> */
            public function toArray($notifiable): array
            {
                return ['message' => 'realtime smoke notification'];
            }
        });
        $assert(in_array('private-user.'.$sender->public_id, $channelsFor('notification.received'), true),
            'database notification broadcasts on the user private channel');
    }

    // ── Scenario 6: clearance cron broadcasts SellerEarningCleared ─────────
    echo "Scenario 6: seller-earning clearance broadcasts SellerEarningCleared\n";
    $saleOrder = Order::create([
        'tracking_token' => (string) Str::ulid(),
        'order_type' => OrderType::P2pSale->value,
        'status' => OrderStatus::Delivered->value,
        'sender_user_id' => $sender->id,
        'sender_phone' => $sender->phone_number,
        'sender_name' => 'Seller',
        'pickup_address' => 'RT sale pickup',
        'pickup_location' => Point::makeGeodetic($pickup['lat'], $pickup['lng']),
        'pickup_code' => '111111',
        'receiver_type' => 'guest',
        'receiver_phone' => $uniquePhone(777),
        'receiver_name' => 'Buyer',
        'receiver_address' => 'RT sale drop',
        'receiver_location' => Point::makeGeodetic($dropoff['lat'], $dropoff['lng']),
        'delivery_code' => '222222',
        'driver_id' => $driver->id,
        'item_description' => 'RT sale item',
        'item_size' => ItemSize::Small->value,
        'item_price' => '55.00',
        'commission_rate' => '0.0500',
        'commission_amount' => '5.00',
        'delivery_fee_base' => '10.00',
        'delivery_fee' => '10.00',
        'driver_fee_cut_amount' => '1.00',
        'delivery_fee_payer' => 'sender',
        'delivery_fee_payment_method' => 'cash',
        'delivery_fee_status' => 'paid',
        'delivered_at' => now(),
        'status_changed_at' => now(),
    ]);
    $earning = SellerEarning::create([
        'order_id' => $saleOrder->id,
        'seller_user_id' => $sender->id,
        'amount' => '50.00',
        'status' => SellerEarningStatus::PendingClearance->value,
        'cleared_at' => now()->subHours(49),
    ]);

    $reset();
    (new ClearSellerEarningsJob)->handle();
    $earning->refresh();
    $assert($earning->status === SellerEarningStatus::Available, 'clearance job promotes earning to available');
    $assert(in_array('private-user.'.$sender->public_id, $channelsFor('seller.earning_cleared'), true),
        'clearance broadcasts seller.earning_cleared on the seller user channel');

    echo "ALL REALTIME SMOKE SCENARIOS PASSED\n";
} finally {
    // Restore shared region/service-area state.
    $region->forceFill($regionOriginal)->save();
    DB::table('service_areas')->where('id', $region->service_area_id)->update(['is_active' => $serviceAreaActive]);

    // Delete everything created during the run (children first). Defensive:
    // skip absent tables and swallow per-statement errors so cleanup never masks
    // the real failure that may have triggered this finally block.
    $cleanup = [
        ['order_status_logs', 'order_id', $snap['orders']],
        ['seller_earnings', 'order_id', $snap['orders']],
        ['orders', 'id', $snap['orders']],
        ['driver_account_transactions', 'driver_id', $snap['users']],
        ['driver_presence_logs', 'driver_id', $snap['users']],
        ['driver_locations', 'driver_id', $snap['users']],
        ['driver_accounts', 'driver_id', $snap['users']],
        ['driver_profiles', 'user_id', $snap['users']],
        ['notifications', 'notifiable_id', $snap['users']],
        ['model_has_roles', 'model_id', $snap['users']],
        ['personal_access_tokens', 'tokenable_id', $snap['users']],
        ['office_locations', 'id', $snap['office_locations']],
        ['users', 'id', $snap['users']],
    ];
    foreach ($cleanup as [$table, $column, $threshold]) {
        try {
            if (Schema::hasTable($table)) {
                DB::table($table)->where($column, '>', $threshold)->delete();
            }
        } catch (Throwable $cleanupError) {
            echo "WARN cleanup of {$table} failed: {$cleanupError->getMessage()}\n";
        }
    }
}
