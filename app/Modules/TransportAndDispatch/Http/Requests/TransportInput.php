<?php

namespace App\Modules\TransportAndDispatch\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class TransportInput extends FormRequest
{
    protected const INSTANT_PATTERN = 'regex:/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:[0-9]{2})\z/';

    public function authorize(): bool
    {
        return $this->user()?->can('manage-transport-core') ?? false;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this instanceof TransportRequestWrite && $this->isMethod('PATCH') && ! $this->hasAny(['requested_liters', 'requested_at'])) {
                $validator->errors()->add('request', 'Debe indicar un campo modificable.');
            }
            foreach (array_diff(array_keys($this->all()), array_merge(array_keys($this->rules()), ['_token'])) as $field) {
                $validator->errors()->add($field, 'Campo no admitido.');
            }
        });
    }
}
