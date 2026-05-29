<?php

declare(strict_types=1);

use App\Http\Middleware\EnsurePasswordChanged;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

uses(RefreshDatabase::class);

function makeRequest(?User $user, string $routeName = 'admin.staff.index'): Request
{
    $request = Request::create('/api/admin/staff');
    if ($user !== null) {
        $request->setUserResolver(fn () => $user);
    }
    $request->setRouteResolver(fn () => (new Route(['GET'], '/api/admin/staff', []))->name($routeName));

    return $request;
}

it('passes through when user has no must_change_password flag', function (): void {
    $user = User::factory()->create(['must_change_password' => false]);

    $next = fn ($req) => response('ok');
    $response = (new EnsurePasswordChanged)->handle(makeRequest($user), $next);

    expect($response->getContent())->toBe('ok');
});

it('passes through when no user is authenticated', function (): void {
    $next = fn ($req) => response('ok');
    $response = (new EnsurePasswordChanged)->handle(makeRequest(null), $next);

    expect($response->getContent())->toBe('ok');
});

it('blocks with 403 when user must change password', function (): void {
    $user = User::factory()->create(['must_change_password' => true]);

    $next = fn ($req) => response('ok');
    $response = (new EnsurePasswordChanged)->handle(makeRequest($user), $next);

    expect($response->getStatusCode())->toBe(403);
    expect($response->getData(true)['error'])->toBe('password_change_required');
});

it('allows the change-from-temp route through', function (): void {
    $user = User::factory()->create(['must_change_password' => true]);

    $next = fn ($req) => response('ok');
    $response = (new EnsurePasswordChanged)->handle(
        makeRequest($user, 'me.password.change-from-temp'),
        $next,
    );

    expect($response->getContent())->toBe('ok');
});

it('allows the logout route through', function (): void {
    $user = User::factory()->create(['must_change_password' => true]);

    $next = fn ($req) => response('ok');
    $response = (new EnsurePasswordChanged)->handle(
        makeRequest($user, 'auth.logout'),
        $next,
    );

    expect($response->getContent())->toBe('ok');
});
