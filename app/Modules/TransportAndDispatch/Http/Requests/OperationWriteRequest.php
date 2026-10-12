<?php

namespace App\Modules\TransportAndDispatch\Http\Requests;

class OperationWriteRequest extends TransportInput
{
    public function rules(): array
    {
        $key = $this->routeIs('transport.operations.load') ? 'compartment_id' : 'load_item_id';

        return ['operation_site_id' => ['required', 'uuid', 'exists:operation_sites,id'], 'occurred_at' => ['required', 'date', self::INSTANT_PATTERN],
            'reason' => ['required', 'string', 'max:5000'], 'evidence' => ['sometimes', 'array', 'min:1'],
            'items' => ['required', 'array', 'min:1', 'max:100'], 'items.*' => ['array:'.$key.',liters'],
            'items.*.'.$key => ['required', 'uuid', 'distinct'],
            'items.*.liters' => ['required', 'string', 'regex:/\A[0-9]{1,13}(?:\.[0-9]{1,2})?\z/']];
    }
}
