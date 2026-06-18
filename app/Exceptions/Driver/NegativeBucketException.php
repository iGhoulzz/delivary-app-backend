<?php

declare(strict_types=1);

namespace App\Exceptions\Driver;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown when a manual adjustment would drive a driver-account bucket below
 * zero. Buckets are non-negative (Critical Rule 5) — we reject, never clamp.
 */
final class NegativeBucketException extends RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => 'bucket_would_go_negative',
            'message' => $this->getMessage(),
        ], 422);
    }
}
