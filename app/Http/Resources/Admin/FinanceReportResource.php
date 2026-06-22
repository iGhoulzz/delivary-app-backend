<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FinanceReportResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    private string $range;

    /** @var array{public_id: string, name: string}|null */
    private ?array $office;

    /**
     * @param  array<string, mixed>  $payload  Result from FinanceReportService::build()
     * @param  array{public_id: string, name: string}|null  $office
     */
    public function __construct(array $payload, string $range, ?array $office)
    {
        parent::__construct($payload);
        $this->range = $range;
        $this->office = $office;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->resource;

        return [
            'range' => $this->range,
            'office' => $this->office,
            'accrued' => $payload['accrued'],
            'cash' => $payload['cash'],
            'gap' => $payload['gap'],
            'by_source' => $payload['by_source'],
            'by_merchant' => $payload['by_merchant'],
            'by_office' => $payload['by_office'],
            'daily_trend' => $payload['daily_trend'],
            'recent_orders' => $payload['recent_orders'],
        ];
    }
}
