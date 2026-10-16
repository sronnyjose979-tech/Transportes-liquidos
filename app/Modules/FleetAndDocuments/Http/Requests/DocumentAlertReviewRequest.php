<?php

namespace App\Modules\FleetAndDocuments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class DocumentAlertReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-fleet-documents') ?? false;
    }

    public function rules(): array
    {
        return [];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['_token']) as $field) {
                $validator->errors()->add($field, 'La revisión no admite campos de actor ni cambios documentales.');
            }
        }];
    }
}
