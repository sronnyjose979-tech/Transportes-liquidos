<?php

namespace App\Modules\AdministrativeReports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReportQueryRequest extends FormRequest
{
    public const STATES = [
        'transport' => ['pending', 'in_progress', 'completed', 'cancelled'],
        'billing' => ['pending', 'partially_paid', 'paid'],
        'expenses' => ['pending', 'approved', 'rejected'],
        'workshop' => ['pending_diagnosis', 'pending_authorization', 'authorized', 'in_repair', 'finished', 'closed', 'cancelled'],
        'stock' => [], 'movements' => ['initial', 'adjustment', 'reversal', 'receipt', 'delivery', 'usage', 'return'],
    ];

    public function authorize(): bool
    {
        return $this->user()?->can('view-administrative-reports') ?? false;
    }

    public function rules(): array
    {
        $kind = $this->route('kind');

        return [
            'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100',
            'from' => $kind === 'stock' ? 'prohibited' : 'sometimes|date_format:Y-m-d',
            'to' => $kind === 'stock' ? 'prohibited' : array_merge(['sometimes', 'date_format:Y-m-d'], $this->filled('from') ? ['after_or_equal:from'] : []),
            'customer_id' => in_array($kind, ['transport', 'billing']) ? 'sometimes|uuid|exists:customers,id' : 'prohibited',
            'fleet_asset_id' => in_array($kind, ['transport', 'workshop']) ? 'sometimes|uuid|exists:fleet_assets,id' : 'prohibited',
            'currency' => in_array($kind, ['billing', 'expenses']) ? ['sometimes', Rule::in(['CRC', 'USD'])] : 'prohibited',
            'status' => $kind !== 'stock' ? ['sometimes', Rule::in(self::STATES[$kind] ?? [])] : 'prohibited',
            'spare_part_id' => in_array($kind, ['stock', 'movements']) ? 'sometimes|uuid|exists:spare_parts,id' : 'prohibited',
        ];
    }
}
