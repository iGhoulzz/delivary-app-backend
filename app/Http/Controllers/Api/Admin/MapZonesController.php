<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MapZonesController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'type' => 'FeatureCollection',
            'features' => [],
        ]);
    }
}
