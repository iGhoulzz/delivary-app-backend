<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\Broadcasters\UsePusherChannelConventions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

uses(RefreshDatabase::class);

/**
 * Thin broadcaster that exposes verifyUserCanAccessChannel publicly so we can
 * exercise channel callbacks registered via routes/channels.php without
 * needing a live Reverb / Pusher connection.
 *
 * The NullBroadcaster used in phpunit.xml short-circuits auth() and never
 * evaluates channel callbacks. This shim re-routes auth() to the protected
 * verifyUserCanAccessChannel() in the base Broadcaster, which is exactly the
 * code-path BroadcastController uses in production.
 */
final class TestBroadcaster extends Broadcaster
{
    use UsePusherChannelConventions;

    public function auth($request): mixed
    {
        $channelName = $this->normalizeChannelName(
            $request->channel_name ?? ''
        );

        return $this->verifyUserCanAccessChannel($request, $channelName);
    }

    public function validAuthenticationResponse($request, $result): mixed
    {
        return $result;
    }

    public function broadcast(array $channels, $event, array $payload = []): void {}
}

/**
 * Resolve the channel callbacks registered in routes/channels.php into a
 * TestBroadcaster, then call auth() with the given request.
 *
 * @throws AccessDeniedHttpException
 * @throws AuthorizationException
 */
function channelAuth(Request $request): mixed
{
    /** @var Broadcaster $nullBroadcaster */
    $nullBroadcaster = Broadcast::driver('null');

    // Transfer registered channels to our TestBroadcaster.
    $testBroadcaster = app(TestBroadcaster::class);

    foreach ($nullBroadcaster->getChannels() as $pattern => $callback) {
        $testBroadcaster->channel($pattern, $callback);
    }

    return $testBroadcaster->auth($request);
}

beforeEach(function (): void {
    // Spatie roles are seeded in production via RolesSeeder.
    // Ensure the 'driver' role exists for each test run.
    Role::findOrCreate('driver', 'web');
});

it('authorizes a user on their own user channel', function (): void {
    $user = User::factory()->create();

    $result = channelAuth(
        request()->merge(['channel_name' => 'private-user.'.$user->public_id])
            ->setUserResolver(fn () => $user)
    );

    expect($result)->not->toBeNull();
});

it('rejects a user from another user channel', function (): void {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    $this->expectException(AccessDeniedHttpException::class);

    channelAuth(
        request()->merge(['channel_name' => 'private-user.'.$bob->public_id])
            ->setUserResolver(fn () => $alice)
    );
});

it('authorizes the sender on their order channel', function (): void {
    $sender = User::factory()->create();
    $order = Order::factory()->create(['sender_user_id' => $sender->id]);

    $result = channelAuth(
        request()->merge(['channel_name' => 'private-order.'.$order->public_id])
            ->setUserResolver(fn () => $sender)
    );

    expect($result)->not->toBeNull();
});

it('authorizes the receiver user on their order channel', function (): void {
    $sender = User::factory()->create();
    $receiver = User::factory()->create();
    $order = Order::factory()
        ->withReceiverUser($receiver)
        ->create(['sender_user_id' => $sender->id]);

    $result = channelAuth(
        request()->merge(['channel_name' => 'private-order.'.$order->public_id])
            ->setUserResolver(fn () => $receiver)
    );

    expect($result)->not->toBeNull();
});

it('rejects an unrelated user from an order channel', function (): void {
    $sender = User::factory()->create();
    $stranger = User::factory()->create();
    $order = Order::factory()->create(['sender_user_id' => $sender->id]);

    $this->expectException(AccessDeniedHttpException::class);

    channelAuth(
        request()->merge(['channel_name' => 'private-order.'.$order->public_id])
            ->setUserResolver(fn () => $stranger)
    );
});

it('rejects a non-driver from a driver channel', function (): void {
    $user = User::factory()->create();

    $this->expectException(AccessDeniedHttpException::class);

    channelAuth(
        request()->merge(['channel_name' => 'private-driver.'.$user->public_id])
            ->setUserResolver(fn () => $user)
    );
});

it('authorizes a driver on their own driver channel', function (): void {
    $driver = User::factory()->create();
    $driver->assignRole('driver');

    $result = channelAuth(
        request()->merge(['channel_name' => 'private-driver.'.$driver->public_id])
            ->setUserResolver(fn () => $driver)
    );

    expect($result)->not->toBeNull();
});

it('rejects a driver from another driver channel', function (): void {
    $alice = User::factory()->create();
    $alice->assignRole('driver');
    $bob = User::factory()->create();
    $bob->assignRole('driver');

    $this->expectException(AccessDeniedHttpException::class);

    channelAuth(
        request()->merge(['channel_name' => 'private-driver.'.$bob->public_id])
            ->setUserResolver(fn () => $alice)
    );
});
