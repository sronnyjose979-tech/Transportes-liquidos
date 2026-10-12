<?php

namespace App\Modules\TransportAndDispatch\Http\Requests;

use Illuminate\Validation\Rule;

class OdometerWriteRequest extends TransportInput
{
    public function rules(): array
    {
        $rules = ['reading_value' => ['required', 'string', 'max:100', 'regex:/\A[0-9]+(?:\.[0-9]+)?\z/'],
            'occurred_at' => ['required', 'date', self::INSTANT_PATTERN], 'reason' => ['required', 'string', 'max:5000'], 'evidence' => ['sometimes', 'array', 'min:1']];
        if (! $this->routeIs('transport.odometer.correct')) {
            $rules += ['tractor_assignment_id' => ['required', 'uuid'], 'reading_type' => ['required', Rule::in(['initial', 'final'])]];
        }

        return $rules;
    }
}
