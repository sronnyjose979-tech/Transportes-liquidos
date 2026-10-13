<?php

namespace App\Modules\Workshop\Http\Requests;

use App\Modules\Workshop\Application\WorkshopAccess;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class WorkshopCoreRequest extends WorkshopInput
{
    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }
        $action = str_replace('workshop.core.', '', $this->route()->getName());
        app(WorkshopAccess::class)->roles($this->user(), match ($action) {
            'report-create','report-revise' => ['administrator', 'secretary', 'mechanic'],
            'diagnosis-record','diagnosis-revise' => ['administrator', 'mechanic'],
            default => ['administrator', 'secretary'],
        });

        return true;
    }

    public function rules(): array
    {
        $rules = ['request_key' => ['required', 'uuid'], 'reason' => self::reason()];
        $text = ['required', 'string', 'max:10000', 'regex:/\S/u'];
        $action = str_replace('workshop.core.', '', $this->route()->getName());

        return $rules + match ($action) {
            'order-create' => ['fleet_asset_id' => ['required', 'uuid'], 'kind' => ['required', Rule::in(['corrective', 'preventive'])], 'description' => $text, 'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])], 'created_at' => self::instant()],
            'order-update' => ['version' => ['required', 'integer:strict', 'min:1'], 'description' => ['sometimes', ...array_slice($text, 1)], 'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])]],
            'report-create' => ['fleet_asset_id' => ['required', 'uuid'], 'trip_event_id' => ['nullable', 'uuid'], 'description' => $text, 'occurred_at' => self::instant()],
            'report-revise' => ['description' => $text, 'occurred_at' => self::instant()],
            'report-link' => ['report_id' => ['required', 'uuid']],
            'mechanic-assign' => ['employee_id' => ['required', 'uuid'], 'starts_at' => self::instant()],
            'mechanic-release' => ['ends_at' => self::instant()],
            'diagnosis-record' => ['mechanic_assignment_id' => ['nullable', 'uuid'], 'problem' => $text, 'probable_cause' => $text, 'proposed_repair' => $text, 'occurred_at' => self::instant()],
            'diagnosis-revise' => ['problem' => $text, 'probable_cause' => $text, 'proposed_repair' => $text, 'occurred_at' => self::instant()],
            'admission-link' => ['admission_id' => ['required', 'uuid']],
            default => [],
        };
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);
        $validator->after(function ($validator): void {
            if ($this->routeIs('workshop.core.order-update') && ! $this->hasAny(['description', 'priority'])) {
                $validator->errors()->add('request', 'Debe indicar un campo modificable.');
            }
        });
    }
}
