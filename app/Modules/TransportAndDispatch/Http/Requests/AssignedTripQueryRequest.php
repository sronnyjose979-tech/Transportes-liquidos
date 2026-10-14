<?php

namespace App\Modules\TransportAndDispatch\Http\Requests;

class AssignedTripQueryRequest extends TransportQueryRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view-assigned-trips') ?? false;
    }
}
