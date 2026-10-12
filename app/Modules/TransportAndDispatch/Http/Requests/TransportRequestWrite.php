<?php

namespace App\Modules\TransportAndDispatch\Http\Requests;

class TransportRequestWrite extends TransportInput
{
    public function rules(): array
    {
        if ($this->isMethod('PATCH')) {
            return ['version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:5000'],
                'requested_liters' => ['sometimes', 'string', 'regex:/\A[0-9]{1,13}(?:\.[0-9]{1,2})?\z/', 'numeric', 'gt:0'],
                'requested_at' => ['sometimes', 'date', self::INSTANT_PATTERN]];
        }

        return ['customer_id' => ['required', 'uuid', 'exists:customers,id'], 'liquid_product_id' => ['required', 'uuid', 'exists:liquid_products,id'],
            'route_id' => ['required', 'uuid', 'exists:routes,id'], 'requested_liters' => ['required', 'string', 'regex:/\A[0-9]{1,13}(?:\.[0-9]{1,2})?\z/', 'numeric', 'gt:0'],
            'requested_at' => ['required', 'date', self::INSTANT_PATTERN]];
    }
}
