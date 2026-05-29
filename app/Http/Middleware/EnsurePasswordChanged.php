<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePasswordChanged
{
    private const ALLOWED_ROUTES = [
        'me.password.change-from-temp',
        'auth.logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->must_change_password) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        return response()->json([
            'error' => 'password_change_required',
            'message' => 'You must change your temporary password before using the API.',
            'next' => ['endpoint' => 'POST /api/me/password/change-from-temp'],
        ], 403);
    }
}
