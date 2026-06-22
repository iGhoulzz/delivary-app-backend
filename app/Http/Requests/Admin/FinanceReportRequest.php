<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class FinanceReportRequest extends FormRequest
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
        return [
            'range' => ['nullable', 'string', 'in:today,7d,30d,all'],
            'office_id' => ['nullable', 'string', 'exists:office_locations,public_id'],
        ];
    }
}
