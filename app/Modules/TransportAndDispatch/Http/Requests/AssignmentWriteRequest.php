<?php

namespace App\Modules\TransportAndDispatch\Http\Requests;

use Illuminate\Validation\Rule;

class AssignmentWriteRequest extends TransportInput
{
    public function rules(): array
    {
        $rules = ['resource_kind' => ['required', Rule::in(['driver', 'tractor_unit', 'tanker'])], 'reason' => ['required', 'string', 'max:5000']];
        if (! $this->routeIs('transport.assignments.release')) {
            $rules += ['resource_id' => ['required', 'uuid'],
                'decisions' => ['sometimes', 'array', 'max:10'], 'decisions.*' => ['uuid', 'distinct', 'exists:transport_authorization_decisions,id']];
        }

        return $rules;
    }
}
