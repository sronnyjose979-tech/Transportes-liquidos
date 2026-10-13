<?php

namespace App\Modules\Workshop\Http\Requests;

use App\Modules\Workshop\Application\WorkshopAccess;

class WorkshopQueryRequest extends WorkshopInput
{
    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }
        if ($this->route('kind') === 'costs') {
            app(WorkshopAccess::class)->office($this->user());
        }

        return true;
    }

    public function rules(): array
    {
        $kind = $this->route('kind');
        $rules = ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100']];
        if ($kind === null) {
            return $rules;
        }
        $rules += ['fleet_asset_id' => ['sometimes', 'uuid'], 'work_order_id' => ['sometimes', 'uuid']];
        if (! in_array($kind, ['scope-tasks', 'scope-parts'], true)) {
            $rules += ['from' => ['sometimes', 'string', 'date', ...array_slice(self::instant(), 3)], 'to' => ['sometimes', 'string', 'date', ...array_slice(self::instant(), 3)]];
            if ($this->has('from')) {
                $rules['to'][] = 'after_or_equal:from';
            }
        }
        if ($kind === 'orders') {
            $rules += ['status' => ['sometimes', 'in:pending_diagnosis,pending_authorization,authorized,in_repair,finished,closed,cancelled'], 'priority' => ['sometimes', 'in:low,normal,high,urgent']];
        }
        if (in_array($kind, ['orders', 'admissions'], true)) {
            $rules['kind'] = ['sometimes', 'in:corrective,preventive'];
        }
        if ($kind === 'mechanic-assignments') {
            $rules['employee_id'] = ['sometimes', 'uuid'];
        }
        if (in_array($kind, ['reports', 'diagnoses', 'scopes', 'activities', 'time-events', 'deliveries', 'usages', 'material-needs', 'external-repairs', 'costs'], true)) {
            $rules['effective'] = ['sometimes', 'boolean'];
        }
        if ($kind === 'scopes') {
            $rules['applied'] = ['sometimes', 'boolean'];
        }
        if (in_array($kind, ['admissions', 'impediments'], true)) {
            $rules['open'] = ['sometimes', 'boolean'];
        }
        if ($kind === 'authorization-requests') {
            $rules['pending'] = ['sometimes', 'boolean'];
        }

        return $rules;
    }
}
