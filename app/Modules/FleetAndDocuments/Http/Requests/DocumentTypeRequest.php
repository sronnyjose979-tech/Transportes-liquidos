<?php

namespace App\Modules\FleetAndDocuments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DocumentTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-document-types') ?? false;
    }

    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'required', 'string', 'max:255'],
            'tracks_expiration' => [$presence, 'required', 'boolean'],
            'required_for_tractor_units' => [$presence, 'required', 'boolean'],
            'required_for_tankers' => [$presence, 'required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_token']) as $field) {
                $validator->errors()->add($field, 'Campo no permitido.');
            }
            if ($this->has('name') && is_string($this->input('name')) && trim($this->input('name')) === '') {
                $validator->errors()->add('name', 'El nombre no puede estar vacío.');
            }
        }];
    }
}
