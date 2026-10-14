<?php

namespace App\Modules\TransportAndDispatch\Http\Requests;

use Illuminate\Validation\Rule;

class DriverRecordWriteRequest extends TransportInput
{
    public function authorize(): bool
    {
        return $this->user()?->can('view-assigned-trips') ?? false;
    }

    public function rules(): array
    {
        $rules = ['request_key' => ['required', 'uuid'], 'occurred_at' => ['required', 'date', self::INSTANT_PATTERN]];
        if ($this->routeIs('transport.driver.odometer')) {
            return $rules + ['tractor_assignment_id' => ['required', 'uuid'], 'reading_type' => ['required', Rule::in(['initial', 'final'])],
                'reading_value' => ['required', 'string', 'max:100', 'regex:/\A[0-9]+(?:\.[0-9]+)?\z/'], 'reason' => ['required', 'string', 'max:5000']];
        }

        return $rules + ['type' => ['required', Rule::in(['information', 'incident', 'completion_report'])], 'information' => ['required', 'array:observation'], 'information.observation' => ['required', 'string', 'max:5000']];
    }
}
