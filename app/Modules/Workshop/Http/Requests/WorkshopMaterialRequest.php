<?php

namespace App\Modules\Workshop\Http\Requests;

use App\Modules\Workshop\Application\WorkshopAccess;

class WorkshopMaterialRequest extends WorkshopInput
{
    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }
        $roles = match ($this->route()->getActionMethod()) {
            'delivery', 'cost' => ['administrator', 'secretary'],
            'usage' => ['administrator', 'mechanic'],
            'external', 'rectify', 'batch' => ['administrator'],
            'revise' => in_array($this->route('kind'), ['usage', 'need'], true) ? ['administrator', 'mechanic'] : ['administrator'],
            default => ['administrator', 'secretary', 'mechanic'],
        };
        app(WorkshopAccess::class)->roles($this->user(), $roles);

        return true;
    }

    public const TABLES = ['activity' => 'workshop_activities', 'time' => 'workshop_time_events', 'delivery' => 'workshop_part_deliveries', 'usage' => 'workshop_part_usages', 'external' => 'workshop_external_repairs', 'cost' => 'workshop_cost_records', 'need' => 'workshop_material_needs', 'attachment' => 'workshop_attachments'];

    public function fields(string $kind): array
    {
        $uuid = ['nullable', 'uuid'];
        $common = ['occurred_at' => ['required', ...$this->instant()], 'reason' => $this->reason()];

        return match ($kind) {
            'delivery' => $common + ['scope_part_id' => ['required', 'uuid'], 'delivered_to_assignment_id' => ['required', 'uuid'], 'quantity' => ['required', ...$this->decimal()]],
            'usage' => $common + ['delivery_id' => ['required', 'uuid'], 'mechanic_assignment_id' => ['required', 'uuid'], 'quantity' => ['required', ...$this->decimal()]],
            'need' => $common + ['scope_part_id' => $uuid, 'spare_part_id' => ['required', 'uuid'], 'quantity' => ['required', ...$this->decimal()], 'description' => ['required', 'string', 'max:10000']],
            'external' => ['scope_task_id' => ['required', 'uuid'], 'supplier_id' => ['required', 'uuid'], 'description' => ['required', 'string', 'max:10000'], 'performed_at' => ['required', ...$this->instant()], 'reason' => $this->reason()],
            'cost' => $common + array_fill_keys(['scope_task_id', 'scope_part_id', 'usage_id', 'external_repair_id', 'source_attachment_id', 'covered_by_cost_id'], $uuid) + ['cost_kind' => ['nullable', 'in:labor,part,external'], 'basis' => ['required', 'in:estimated,actual'], 'amount' => ['nullable', ...array_values(array_filter($this->decimal(true), fn ($rule) => $rule !== 'required'))], 'currency' => ['nullable', 'regex:/^[A-Z]{3}$/D'], 'description' => ['required', 'string', 'max:10000']],
            'activity' => $common + ['task_id' => ['required', 'uuid'], 'mechanic_assignment_id' => $uuid, 'description' => ['required', 'string', 'max:10000'], 'observations' => ['nullable', 'string', 'max:10000']],
            'time' => $common + ['mechanic_assignment_id' => ['required', 'uuid'], 'event_type' => ['required', 'in:start,pause,resume,finish']],
            'attachment' => WorkshopAttachmentRequest::reservationFields(),
            default => [],
        };
    }

    public function rules(): array
    {
        $action = $this->route()->getActionMethod();
        if ($action === 'rectify') {
            $fields = $this->fields((string) $this->route('kind'));
            if ($this->route('kind') === 'attachment') {
                $fields = array_intersect_key($fields, array_flip(['category', 'external_repair_id', 'mime', 'extension', 'size_bytes', 'checksum']));
            }
            // A rectification is partial; the backend reconstructs and validates the full fact.
            foreach ($fields as &$rules) {
                $rules = array_values(array_filter($rules, fn ($rule) => $rule !== 'required'));
                array_unshift($rules, 'sometimes');
            }
            unset($rules);

            return ['request_key' => ['required', 'uuid'], 'version' => ['required', 'integer:strict', 'min:1'], 'executor_id' => ['required', 'integer:strict', 'min:1'], 'reason' => $this->reason(), 'changes' => ['required', 'array:'.implode(',', array_keys($fields)), 'min:1']] + collect($fields)->mapWithKeys(fn ($rules, $key) => ['changes.'.$key => $rules])->all();
        }
        if ($action === 'batch') {
            return ['request_key' => ['required', 'uuid'], 'decisions' => ['required', 'array', 'min:1', 'max:100'], 'decisions.*' => ['required', 'uuid', 'distinct']];
        }
        if ($action === 'revise') {
            return ['request_key' => ['required', 'uuid']];
        }

        return ['request_key' => ['required', 'uuid']] + $this->fields($action);
    }
}
