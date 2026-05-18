<?php

declare(strict_types=1);

namespace App\Services\Settlement;

use App\Enums\SellerEarningStatus;
use App\Enums\SellerPayoutStatus;
use App\Enums\SettlementErrorCode;
use App\Exceptions\Settlement\PayoutValidationException;
use App\Models\OfficeLocation;
use App\Models\PlatformSetting;
use App\Models\SellerEarning;
use App\Models\SellerPayout;
use App\Models\SellerPayoutOrder;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sole writer of `seller_payouts` and `seller_payout_orders`.
 * Mutates `seller_earnings` from `available` → `paid_out`.
 *
 * Flow per spec §6.2:
 *   1. lockForUpdate on each selected earning.
 *   2. Validate every earning belongs to seller + status=available + no existing payout link.
 *   3. Sanity check: server-computed total must equal client-submitted total exactly.
 *   4. Enforce payouts.min_amount.
 *   5. Insert SellerPayout receipt row + flip earnings + write seller_payout_orders pivot.
 */
final class SellerPayoutService
{
    /**
     * @return Collection<int, SellerEarning>
     */
    public function availableEarningsFor(User $seller): Collection
    {
        return SellerEarning::query()
            ->forSeller($seller->id)
            ->available()
            ->with('order:id,public_id,item_description,order_type,delivered_at')
            ->orderBy('available_at')
            ->get();
    }

    /**
     * @param  Collection<int, string>  $earningPublicIds
     */
    public function process(
        User $seller,
        User $staff,
        OfficeLocation $office,
        Collection $earningPublicIds,
        string $totalForSanityCheck,
        ?string $notes = null,
    ): SellerPayout {
        if ($earningPublicIds->isEmpty()) {
            throw new PayoutValidationException(
                SettlementErrorCode::PayoutEmptySelection,
                'At least one earning must be selected.',
            );
        }

        return DB::transaction(function () use (
            $seller,
            $staff,
            $office,
            $earningPublicIds,
            $totalForSanityCheck,
            $notes,
        ): SellerPayout {
            $earnings = SellerEarning::query()
                ->whereIn('public_id', $earningPublicIds->all())
                ->lockForUpdate()
                ->get();

            if ($earnings->count() !== $earningPublicIds->count()) {
                throw new PayoutValidationException(
                    SettlementErrorCode::PayoutEarningNotAvailable,
                    'One or more selected earnings no longer exist.',
                );
            }

            foreach ($earnings as $earning) {
                if ($earning->seller_user_id !== $seller->id) {
                    throw new PayoutValidationException(
                        SettlementErrorCode::PayoutEarningWrongSeller,
                        "Earning {$earning->public_id} does not belong to seller {$seller->public_id}.",
                    );
                }
                if ($earning->status !== SellerEarningStatus::Available) {
                    throw new PayoutValidationException(
                        SettlementErrorCode::PayoutEarningNotAvailable,
                        "Earning {$earning->public_id} is not in 'available' state (current: {$earning->status->value}).",
                    );
                }
                if ($earning->seller_payout_id !== null) {
                    throw new PayoutValidationException(
                        SettlementErrorCode::PayoutEarningNotAvailable,
                        "Earning {$earning->public_id} is already linked to a payout.",
                    );
                }
            }

            $computedTotal = $earnings->reduce(
                static fn (string $carry, SellerEarning $e): string => bcadd($carry, (string) $e->amount, 2),
                '0.00',
            );
            if (bccomp($computedTotal, $totalForSanityCheck, 2) !== 0) {
                throw new PayoutValidationException(
                    SettlementErrorCode::PayoutTotalMismatch,
                    "Submitted total {$totalForSanityCheck} does not match computed total {$computedTotal}.",
                );
            }

            $minAmount = (string) PlatformSetting::get('payouts.min_amount', '20.00');
            if (bccomp($computedTotal, $minAmount, 2) === -1) {
                throw new PayoutValidationException(
                    SettlementErrorCode::PayoutBelowMinimum,
                    "Total {$computedTotal} is below minimum payout {$minAmount}.",
                );
            }

            $payout = SellerPayout::create([
                'user_id' => $seller->id,
                'amount' => $computedTotal,
                'payout_method' => 'cash_at_office',
                'office_id' => $office->id,
                'status' => SellerPayoutStatus::Paid->value,
                'paid_at' => now(),
                'paid_by_staff_id' => $staff->id,
                'notes' => $notes,
            ]);

            $now = now();
            foreach ($earnings as $earning) {
                $earning->status = SellerEarningStatus::PaidOut->value;
                $earning->paid_out_at = $now;
                $earning->paid_by_staff_id = $staff->id;
                $earning->seller_payout_id = $payout->id;
                $earning->save();

                SellerPayoutOrder::create([
                    'seller_payout_id' => $payout->id,
                    'order_id' => $earning->order_id,
                    'amount_contributed' => (string) $earning->amount,
                ]);
            }

            return $payout->fresh(['user', 'office', 'paidByStaff', 'orders', 'earnings']);
        });
    }
}
