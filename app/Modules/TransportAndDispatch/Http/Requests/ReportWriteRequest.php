<?php

namespace App\Modules\TransportAndDispatch\Http\Requests;

use App\Modules\TransportAndDispatch\Models\Trip;
use Illuminate\Validation\Rule;

class ReportWriteRequest extends TransportInput
{
    public function authorize(): bool
    {
        $trip = Trip::query()->findOrFail($this->route('id'));

        return $this->user()?->can('report-trip-information', $trip) ?? false;
    }

    public function rules(): array
    {
        return ['type' => ['required', Rule::in(['information', 'completion_report', 'incident'])],
            'information' => ['required', 'array', 'min:1'], 'occurred_at' => ['required', 'date', self::INSTANT_PATTERN]];
    }
}
