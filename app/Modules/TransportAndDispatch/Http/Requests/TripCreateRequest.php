<?php

namespace App\Modules\TransportAndDispatch\Http\Requests;

class TripCreateRequest extends TransportInput
{
    public function rules(): array
    {
        return ['request_key' => ['required', 'uuid'], 'transport_request_id' => ['required', 'uuid', 'exists:transport_requests,id']];
    }
}
