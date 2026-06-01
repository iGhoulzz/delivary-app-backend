<?php

declare(strict_types=1);

namespace App\Http\Resources\Driver;

use App\Models\DriverAccountTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DriverAccountTransaction */
final class DriverAccountTransactionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'bucket' => $this->bucket instanceof \BackedEnum ? $this->bucket->value : $this->bucket,
            'amount' => (string) $this->amount,
            'reason' => $this->reason instanceof \BackedEnum ? $this->reason->value : $this->reason,
            'balance_after' => (string) $this->balance_after,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
