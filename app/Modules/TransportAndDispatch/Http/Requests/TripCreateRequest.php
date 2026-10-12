<?php

namespace App\Modules\TransportAndDispatch\Http\Requests;

class TripCreateRequest extends TransportInput
{
    public function rules(): array
    {
        return ['transport_request_id' => ['required', 'uuid', 'exists:transport_requests,id']];
    }
}
