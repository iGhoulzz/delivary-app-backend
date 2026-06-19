<?php

declare(strict_types=1);

namespace App\Exceptions\Driver;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown when an admin tries to void a strike that is already voided. Voiding
 * is an audit action, so repeated submissions are visibly rejected (422). The
 * check runs under a row lock so two concurrent voids cannot both succeed.
 */
final class StrikeAlreadyVoidedException extends RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json(['error' => 'strike_already_voided'], 422);
    }
}
