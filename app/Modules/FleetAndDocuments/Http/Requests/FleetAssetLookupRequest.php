<?php

namespace App\Modules\FleetAndDocuments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FleetAssetLookupRequest extends FormRequest
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
        ];
    }
}
