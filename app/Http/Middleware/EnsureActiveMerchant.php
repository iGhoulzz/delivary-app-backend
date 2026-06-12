<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\MerchantErrorCode;
use App\Enums\MerchantStatus;
use App\Exceptions\Merchant\MerchantException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the merchant order flow: the user must hold the `merchant` role AND
 * have an active merchant profile. The role is the coarse gate, the status the
 * fine one — a suspended/banned merchant keeps the role only until ban.
 */
final class EnsureActiveMerchant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $active = $user !== null
            && $user->hasRole('merchant')
            && $user->merchantProfile?->status === MerchantStatus::Active;

        if (! $active) {
            throw new MerchantException(
                MerchantErrorCode::MerchantNotActive,
                trans('merchant_messages.merchant_not_active'),
            );
        }

        return $next($request);
    }
}
