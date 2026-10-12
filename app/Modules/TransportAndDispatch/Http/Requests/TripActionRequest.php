<?php

namespace App\Modules\TransportAndDispatch\Http\Requests;

class TripActionRequest extends TransportInput
{
    public function rules(): array
    {
        $rules = ['version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:5000']];
        if ($this->routeIs('transport.trips.schedule', 'transport.trips.reschedule')) {
            $rules += [
                'scheduled_start_at' => ['required', 'date', self::INSTANT_PATTERN], 'scheduled_end_at' => ['nullable', 'date', self::INSTANT_PATTERN, 'after_or_equal:scheduled_start_at']];
        }
        if ($this->routeIs('transport.trips.finish')) {
            $rules += ['decision_id' => ['nullable', 'uuid', 'exists:transport_authorization_decisions,id']];
        }
        if ($this->routeIs('transport.trips.close')) {
            $rules += ['warnings' => ['sometimes', 'array', 'max:100'],
                'warnings.*' => ['array:code,evidence'], 'warnings.*.code' => ['required', 'string', 'max:100'],
                'warnings.*.evidence' => ['required', 'array', 'min:1']];
        }

        return $rules;
    }
}
