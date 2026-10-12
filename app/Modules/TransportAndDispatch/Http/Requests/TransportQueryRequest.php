<?php

namespace App\Modules\TransportAndDispatch\Http\Requests;

use Illuminate\Validation\Rule;

class TransportQueryRequest extends TransportInput
{
    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'transport_request_id' => ['sometimes', 'uuid'], 'execution_status' => ['sometimes', Rule::in(['pending', 'in_progress', 'completed', 'cancelled'])],
            'administrative_status' => ['sometimes', Rule::in(['open', 'closed'])], 'resource_kind' => ['sometimes', Rule::in(['driver', 'tractor_unit', 'tanker'])],
            'resource_id' => ['required_with:resource_kind', 'uuid'], 'tractor_assignment_id' => ['sometimes', 'uuid']];
    }
}
