<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\OverviewResource;
use App\Services\Reporting\OverviewMetricsService;

final class OverviewController extends Controller
{
    public function __construct(private readonly OverviewMetricsService $metrics) {}

    public function __invoke(): OverviewResource
    {
        return new OverviewResource($this->metrics->build());
    }
}
