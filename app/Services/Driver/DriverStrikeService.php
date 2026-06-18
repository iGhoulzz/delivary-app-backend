<?php

declare(strict_types=1);

namespace App\Services\Driver;

use App\Enums\DriverAccountTransactionReason;
use App\Enums\DriverStrikeIssuer;
use App\Enums\DriverStrikeReason;
use App\Models\DriverStrike;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DriverStrikeService
{
    public function __construct(private readonly DriverAccountLedgerService $ledger) {}

    /**
     * Issue a manual (admin) strike. If a fee is set it is posted through the
     * existing locked + ledgered fee path (debits earnings first, remainder to
     * debt) under reason `strike_fee`, referencing the strike.
     */
    public function addManual(User $driver, DriverStrikeReason $reason, string $fee, int $adminId, ?string $notes = null): DriverStrike
    {
        return DB::transaction(function () use ($driver, $reason, $fee, $adminId, $notes): DriverStrike {
            $strike = DriverStrike::create([
                'driver_id' => $driver->id,
                'reason' => $reason->value,
                'fee_amount' => $fee,
                'issued_by' => DriverStrikeIssuer::Admin->value,
                'issued_by_admin_id' => $adminId,
                'notes' => $notes,
            ]);

            if (bccomp($fee, '0.00', 2) === 1) {
                $this->ledger->applyFee($driver, $fee, DriverAccountTransactionReason::StrikeFee, $strike, $adminId, $notes);
            }

            return $strike;
        });
    }

    /**
     * Void a strike — a pure status flip. Never reverses any fee: a refund, if
     * wanted, is a separate explicit manual adjustment (spec §6 decisions).
     */
    public function void(DriverStrike $strike, string $reason, int $adminId): DriverStrike
    {
        $strike->forceFill([
            'is_voided' => true,
            'voided_at' => now(),
            'voided_by_admin_id' => $adminId,
            'void_reason' => $reason,
        ])->save();

        return $strike;
    }
}
