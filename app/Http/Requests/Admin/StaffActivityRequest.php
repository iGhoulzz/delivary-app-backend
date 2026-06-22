<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class StaffActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $validKinds = implode(',', [
            'order_action',
            'settlement_processed',
            'seller_payout_paid',
            'account_moderation',
            'driver_account_adjustment',
            'driver_strike_issued',
            'driver_strike_voided',
            'office_return_received',
            'office_order_retrieved',
            'driver_approved',
            'driver_document_verified',
            'merchant_onboarded',
            'merchant_approved',
            'setting_updated',
            'order_abandoned',
        ]);

        return [
            'kinds' => ['nullable', 'array'],
            'kinds.*' => ['string', 'in:'.$validKinds],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
