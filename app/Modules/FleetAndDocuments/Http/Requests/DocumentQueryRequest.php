<?php

namespace App\Modules\FleetAndDocuments\Http\Requests;

use App\Modules\FleetAndDocuments\Domain\DocumentValidity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DocumentQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-fleet-documents') ?? false;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'validity' => ['sometimes', Rule::enum(DocumentValidity::class)],
            'document_type_id' => ['sometimes', 'uuid'],
            'fleet_asset_id' => ['sometimes', 'uuid'],
        ];
    }
}
