<?php

use App\Exceptions\Merchant\MerchantException;
use App\Exceptions\Moderation\ModerationException;
use App\Exceptions\Order\OrderDomainException;
use App\Exceptions\Staff\StaffDomainException;
use App\Http\Middleware\EnsureActiveMerchant;
use App\Http\Middleware\EnsurePasswordChanged;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Spatie Permission middleware aliases — registered explicitly so
        // routes can use `role:admin`, `permission:foo`, etc.
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'staff.password_change_required' => EnsurePasswordChanged::class,
            'active.merchant' => EnsureActiveMerchant::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Render order-domain exceptions as structured JSON using the
        // error code's mapped HTTP status. Mirrors how driver-domain
        // exceptions are surfaced from controllers via OrderErrorCode.
        $exceptions->render(function (OrderDomainException $e): JsonResponse {
            return new JsonResponse($e->toResponse(), $e->httpStatus());
        });

        $exceptions->render(function (StaffDomainException $e): JsonResponse {
            return new JsonResponse($e->toResponse(), $e->httpStatus());
        });

        $exceptions->render(function (ModerationException $e): JsonResponse {
            return new JsonResponse($e->toResponse(), $e->httpStatus());
        });

        $exceptions->render(function (MerchantException $e): JsonResponse {
            return new JsonResponse($e->toResponse(), $e->httpStatus());
        });
    })->create();
