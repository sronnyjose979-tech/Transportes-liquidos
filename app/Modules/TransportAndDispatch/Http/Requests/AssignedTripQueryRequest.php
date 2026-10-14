<?php

namespace App\Modules\TransportAndDispatch\Http\Requests;

class AssignedTripQueryRequest extends TransportQueryRequest
{
    public function rules(): array
    {
        return parent::rules() + ['trip_id' => ['sometimes', 'uuid']];
    }

    public function authorize(): bool
    {
        return $this->user()?->can('view-assigned-trips') ?? false;
    }
}
