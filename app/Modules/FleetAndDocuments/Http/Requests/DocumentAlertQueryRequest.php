<?php

namespace App\Modules\FleetAndDocuments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DocumentAlertQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-fleet-documents') ?? false;
    }

    public function rules(): array
    {
        return ['employee_id' => 'prohibited', 'person_id' => 'prohibited', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100',
            'status' => ['sometimes', Rule::in(['expired', 'expiring_soon'])],
            'attention' => ['sometimes', Rule::in(['reviewed', 'unreviewed'])],
            'asset_type' => ['sometimes', Rule::in(['tractor_unit', 'tanker'])],
            'fleet_asset_id' => 'sometimes|uuid|exists:fleet_assets,id', 'document_type_id' => 'sometimes|uuid|exists:document_types,id',
            'from' => 'sometimes|date_format:Y-m-d',
            'to' => array_merge(['sometimes', 'date_format:Y-m-d'], $this->filled('from') ? ['after_or_equal:from'] : []),
        ];
    }
}
