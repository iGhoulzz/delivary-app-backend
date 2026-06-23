<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FinanceReportRequest;
use App\Http\Resources\Admin\FinanceReportResource;
use App\Models\OfficeLocation;
use App\Services\Reporting\FinanceReportService;

final class FinanceReportController extends Controller
{
    public function __construct(private readonly FinanceReportService $service) {}

    public function __invoke(FinanceReportRequest $request): FinanceReportResource
    {
        $range = $request->string('range')->value() ?: '30d';
        $publicId = $request->string('office_id')->value() ?: null;

        $officeId = null;
        $officeData = null;

        if ($publicId !== null) {
            $office = OfficeLocation::where('public_id', $publicId)->first();

            if ($office !== null) {
                $officeId = $office->id;
                $officeData = ['public_id' => $office->public_id, 'name' => $office->name];
            }
        }

        $payload = $this->service->build($range, $officeId);

        return new FinanceReportResource($payload, $range, $officeData);
    }
}
